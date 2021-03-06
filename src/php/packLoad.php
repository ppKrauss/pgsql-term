<?php
/**
 * Basic SQL-LoadPack library and util functions, PHP implementation.
 * ... Like an engine for "make script", but with some other approach.
 * @version v1.0
 * @author  ppkrauss
 * @license MIT
 * @see https://github.com/ppKrauss/sql-loadPack
 */

// // // //
// CONFIGS:
$PG_USER = 'postgres';   // or see use of terminal user by http://stackoverflow.com/a/17725036/287948
$PG_PW   = 'pp@123456';  // (or include secure/configs.php)
$databaseName = 'postgres';   // database
$dsn        = "pgsql:dbname=$databaseName;host=localhost";
$dbTerminal = "psql -h localhost -U $PG_USER  -W $databaseName";  // (for assert dump) no way to send $PG_PW?

// // //
// INI
$db = new pdo($dsn,$PG_USER,$PG_PW);

/////////////////
// MAIN FUNCTIONS

/**
 * SQL database preparation, running SQL scripts.
 * @param $INI array of SQL blocks
 * @param $MSG a general message about what is iniciatizating
 * @param $do  false abort this function
 */
function sql_prepare($INI,$MSG='',$basePath='',$do=true, $assertStop=3) {
	global $db;
	global $dbTerminal;
	if (!is_array($INI)) $INI = [$INI];
	$time_start = microtime(true);
	$affected = 0;
	if ($do) {
		print "\n --- BEGIN SQL SCRIPTS ".($MSG? ": $MSG": '')." \n";
		foreach($INI as $sql)
			if (preg_match("/^::assert:([^\n]+\.sql)\$/",$sql,$m)) {   // assert files
				print "\n\t... running assert $m[1]...";
				$file = "$basePath/$m[1]";
				$out = shell_exec("$dbTerminal < $file");
				$cmpfile = preg_replace('/\.sql$/','.dump.txt',$file);
				if (file_get_contents($cmpfile)!=$out)
					die("\n--- ASSERT ERROR ON $file ----\n\n(((BEGIN DEBUG:\n$out\nEND DEBUG)))\n\n");
				else
					print " ok!";

			} elseif (preg_match("/^::assert(_bysql)?:([^\n]+\.tsv)\$/",$sql,$m)) {   // assert TSV file
				$bysql = $m[1];
				print "\n\t... loading assert $m[2]...";
				$file = "$basePath/$m[2]";
				if ($bysql) {
					$sql = "
								COPY tstore._assert (alabel,sql_select,result)
								FROM '{$file}'
								WITH (FORMAT csv, DELIMITER E'\\t', QUOTE E'\\b', HEADER)
					";
					sql_exec($db, $sql, "\n\t... building assert table");
					print "\n\t... running assert";
					if ($out = $db->query("SELECT tlib._assert_outextab(false,1)")->fetchColumn())
						die("\n ASSERT ERRORS: $out\n");
					else
						print "\n\t... conclude assert: ALL OK.";

				} else {
					$h = fopen($file,'r'); // 0=alabel	1=sql_select	2=return
					$label0 = '';
					$nErrors=0;
					while( $h && !feof($h) )
					 if ((list($label,$sql,$out) = fgetcsv($h,0,"\t")) && isset($sql) && $sql!='sql_select') {
						if ($label!=$label0) {print "\n\t Assert group $label: "; $label0=$label;}
						$sql0 = trim($sql);
						$sql = (strtolower(substr($sql0,0,6))=='select')? $sql: "SELECT $sql"; // little parse to be little friendly
						if ($db->query($sql)->fetchColumn() != $out) {
							print "\n ASSERT ERROR ON SQL: ((\n$sql\n)) expected '''\n$out\n'''\n";
							if (++$nErrors>$assertStop) die("\n END: TOO MANY ASSERT-ERRORS\n");
						} else print "ok ";
					}  // while if
				} // else
				print "\n\tEND assert";

			} elseif (preg_match("/^::([^\n]+\.sql)\$/",$sql,$m))  // normal file
					$affected += sql_exec($db, file_get_contents("$basePath/$m[1]"), "\n\t... running script from file");
			else
					$affected += sql_exec($db, $sql, "\n\t... running script");

		$time_end = microtime(true);
		$execution_time = round($time_end - $time_start,2);
		print "\n --- END SCRIPTS, SUCESS ($affected rows affected on initialization) spending $execution_time seconds\n";
	}
	return $affected;
}

/**
 * Load resource data (from datapackage or $etc).
 * @param $basePath array of SQL blocks
 * @param $items  array of pairs command-param
 * @param $MSG a general message or items-description.
 * @param $etc  string ($jfieldName) or array with replacement for datapackage.
 */
function resourceLoad_run($basePath,$items,$MSG='',$etc='jinfo'){
	global $db;
	if (is_array($etc)) {   // a datapackage replacement
		$packs = $etc['packs'];
		$jfieldName = $etc['jfieldName'];
	} else{
		$jfieldName=$etc;
		$packs = unpack_datapackage($basePath);  // mais de uma
	}
	$time_start = microtime(true);
	print "\n\tBEGIN processing $MSG ...";
	$affected=0;
	foreach ($items as $resName=>$preps) foreach($preps as $args) if (isset($packs[$resName])) {
				$cmd = array_shift($args);
				print "\n\t... Running $resName with '$cmd'";
				$p = $packs[$resName];  // with-wrap
				$is_jsonb = false;
				switch ($cmd) {
				case 'prepared_copy': // the target table was prepared before. arg1=table name.
					$sql = "COPY $args[0] FROM '{$p['file']}' DELIMITER '{$p['sep']}' CSV HEADER;";
					$affected += sql_exec($db, $sql, "\n\t... $cmd($args[0]) ");
					break;
				default:  // automatic, creating table as tmp_name
					$cmd='prepare_auto';
				case 'prepare_jsonb':
					$is_jsonb = ($cmd!='prepare_auto'); // true
				case 'prepare_auto':
				case 'prepare_json':
					 // the target table will be created. arg1=table name.
					$fields0 = join(' text, ',$p['fields_sql']).' text';
					$fields2 = $fields0b = $fields3 = $fields3s = '';
					$fields1 = join(',',$p['fields_sql']);
					if (count($p['fields_json'])) {
						$fields0b =", $jfieldName JSON".($is_jsonb?'B':''); $fields1x =$fields1.", $jfieldName";
						$fields2 = ", ".join(' Text, ',$p['fields_json']).' text';
						$fields3 = join(',',$p['fields_json']);
						$fields3s = "'".join("','",$p['fields_json'])."'";
					}
					$sql = "CREATE TABLE $args[0] (id serial PRIMARY KEY, $fields0$fields0b);";
					if (count($p['fields_json'])) {
						$sql .= "CREATE TABLE {$args[0]}_tmp ($fields0$fields2);";
					}
					$affected += sql_exec($db, $sql, "\n\t... creating table...");
					if (count($p['fields_json'])) {
						$sql = "COPY {$args[0]}_tmp FROM '{$p['file']}' DELIMITER '{$p['sep']}' CSV HEADER;";
						$affected += sql_exec($db, $sql, "\n\t... loading tmp $cmd($args[0]) ");
						if ($is_jsonb) {
							$pairs = [];
							for ($i=0; $i<count($p['fields_json']); $i++) if ($p['fields_json'][$i])
								$pairs[] = "'{$p['fields_json'][$i]}',{$p['fields_json'][$i]}::".json2sqltype($p['fields_json_types'][$i]);
							$pairs = join(', ',$pairs);
							$sql = "INSERT INTO {$args[0]} ($fields1x)
							  SELECT $fields1,jsonb_build_object($pairs)
							  FROM {$args[0]}_tmp;
							";
						} else
							$sql = "INSERT INTO {$args[0]} ($fields1x)
							  SELECT $fields1,json_object(array[ {$fields3s} ],array[ {$fields3} ])
							  FROM {$args[0]}_tmp;
							";
						$affected += sql_exec($db, $sql, "\n\t... INSERT tmp ... ");
						$affected += sql_exec($db, "DROP TABLE {$args[0]}_tmp;", "\n\t... DROP tmp ... ");
					}
					break;

				case 'commom':  // standad _tmp table
						die("\nOOPS under construction");
						break;
				} // switch
				$time_end = microtime(true);
				$execution_time = round($time_end - $time_start,2);
				print "\n\tEND PROCESSING (acum. $affected rows affected) spending $execution_time seconds.\n";
			} // for if
		else
			die("\n\t-- BUG: items requerindo '$resName' inexistente no Pack\n");
			//var_dump($packs);
	}


//////////////////
// OTHER FUNCTIONS

/**
 * Get all basic information for CSV interpretation.
 * See also http://data.okfn.org/doc/tabular-data-package
 * @return array =>resource_name=>(
 *		file, sep, fields_sql, fields_json, fields_json_types
 *   )
 */
function unpack_datapackage($folder,$dftSEP=',',$prefURL=false) {
	$p = "$folder/datapackage.json";
	if (!file_exists($p))
		die("\n\t-- ERROR: check folder ($folder) or add datapackage.json");
	$jpack = json_decode( file_get_contents($p), true );
	$ret = [];
	foreach($jpack['resources'] as $pack) if ( isset($pack['path']) ) {
		$r = [];
		$rpath = $pack['path'];
		$name = isset($pack['name'])? $pack['name']: $rpath; // or get filename
		print "\n\t -- analysing pack-resource '$name'";

		$r['sep'] = isset($pack['sep'])? $pack['sep']: $dftSEP;
		if (isset($pack['schema']['fields']))
			list($r['fields_sql'],$r['fields_json'],$r['fields_json_types']) = fields_to_parts($pack['schema']['fields'],false,true);
		else
			die("\n\t -- ERROR at datapackage.json: no schema/fields in $name\n");
		$r['file'] = ((!$rpath || $prefURL) && isset($pack['url']))? $pack['url']: realpath("$folder/$rpath");
		$ret[$name] = $r;
	  }
	return $ret;
}

function json2sqltype($t){ // ver tb $json2phpType
	static $fromto = ['number'=>'numeric','string'=>'text','boolean'=>'boolean',	'integer'=>'integer','float'=>'float'];
			//print " conv(debug $t=..)";
	return isset($fromto[$t])? $fromto[$t]: $t;
}


/**
 * Initialization, json_to_sql() function complement.
 */
function fields_to_parts($fields,$only_names=true,$useType=false) {
	$sql_fields = array();
	$json_fields = array();
	$json_types = array();
	// to PDO use PDO::PARAM_INT, PDO::PARAM_BOOL,PDO::PARAM_STR
	$json2phpType = array( // see datapackage jargon, that is flexible...
		'integer'=>'integer', 'int'=>'integer',
		'boolean'=>'boolean', 'number'=>'float', 'string'=>'string'
	);
	if (count($fields)) {
	  foreach($fields as $ff) {
			$name = str_replace([' ','-'],['_','_'],strtolower($only_names? $ff: $ff['name']));
			if ( !$only_names && isset($ff['role']) ) {   // e outros recursos do prepare
				$sql_fields[]  = $name;
			} else {
				$json_fields[] = $name;
				if ( $useType && isset($ff['type']) ) {
					// parse with http://php.net/manual/en/pdo.constants.php
					$t = strtolower($ff['type']);
					$json_types[] = isset($json2phpType[$t])? $json2phpType[$t]: 'string';
				} else
					$json_types[] = 'string';// PDO::PARAM_STR;
			} // else
	   } // for
	} // else return ...
	return ($only_names?
		  $json_fields:  // all as fields
		  ($useType? [$sql_fields,$json_fields,$json_types]: [$sql_fields,$json_fields])
	);
}

/////////////////
// ERROR HANDLING

function sql_exec(PDO $db,$sql,$msg="", $showAffect=true) {
    if ($msg) print $msg;
    $affected = $db->exec($sql);  // integer?
    if ($affected === false) {
        $err = $db->errorInfo();
        if ($err[0] === '00000' || $err[0] === '01000') {
            return true;
        } else
	    die("\n--ERRO AO RODAR SQL: \n---------\n".substr(str_replace("\n","\n\t",$sql),0,300)."\n-----------\n".implode(':',$err)."\n");
    }
		if ($showAffect) print " ($affected affected)";
    return $affected;
}



?>
