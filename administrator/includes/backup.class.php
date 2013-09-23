<?php

// no direct access
defined( '_VALID_JOURNALNESS' ) or die( 'Restricted access' );

class Backup {

	function Backup(){

	}

      // -----------------------
      // The following functions are adapted from phpMyAdmin and upgrade_20.php
      //
      function gzip_PrintFourChars($Val)
      {
		$return = NULL;
      	for ($i = 0; $i < 4; $i ++)
      	{
      		$return .= chr($Val % 256);
      		$Val = floor($Val / 256);
      	}
      	return $return;
      }
      
      
      
      //
      // This function is used for grabbing the sequences for postgres...
      //
      function pg_get_sequences($crlf, $backup_type)
      {
      	global $database, $journalnessConfig_dbprefix;
		$prefix = $journalnessConfig_dbprefix;
      
      	$get_seq_sql = "SELECT relname FROM pg_class WHERE relname LIKE '" . $prefix . "%' AND relname NOT LIKE 'pg_%' AND relname NOT LIKE 'sql_%' AND relkind='S'";
      
      	$seq = $database->GetArray($get_seq_sql);
      
      	if( !$num_seq = count($seq) )
      	{
      
      		$return_val = "# No Sequences Found $crlf";
      
      	}
      	else
      	{
      		$return_val = "# Sequences $crlf";
      		$i_seq = 0;
      
      		while($i_seq < $num_seq)
      		{
      			$row = $seq[$i_seq];
      			$sequence = $row['relname'];
      
      			$get_props_sql = "SELECT * FROM $sequence";
      			$seq_props = $database->GetArray($get_props_sql);
      
      			if(count($seq_props) > 0)
      			{

      				$row1 = $seq_props[0];
      
      				if($backup_type == 'structure')
      				{
      					$row1['last_value'] = 1;
      				}
      
      				$return_val .= "CREATE SEQUENCE $sequence start " . $row1['last_value'] . ' increment ' . $row1['increment_by'] . ' maxvalue ' . $row1['max_value'] . ' minvalue ' . $row1['min_value'] . ' cache ' . $row1['cache_value'] . "; $crlf";
      
      			}  // End if numrows > 0
      
				/*
      			if(($row1['last_value'] > 1) && ($backup_type != 'structure'))
      			{
      				$return_val .= "SELECT NEXTVALUE('$sequence'); $crlf";
      				unset($row1['last_value']);
      			}*/
      
      			$i_seq++;
      
      		} // End while..
      
      	} // End else...
      
      	return $return_val;
      
      } // End function...
      
      //
      // The following functions will return the "CREATE TABLE syntax for the
      // varying DBMS's
      //
      // This function returns, will return the table def's for postgres...
      //
      function get_table_def_postgresql($table, $crlf)
      {
      	global $drop, $database;

		$index_create = NULL;
		$primary_key = NULL;
      
      	$schema_create = "";
      	//
      	// Get a listing of the fields, with their associated types, etc.
      	//
      
      	$field_query = "SELECT a.attnum, a.attname AS field, t.typname as type, a.attlen AS length, a.atttypmod as lengthvar, a.attnotnull as notnull
      		FROM pg_class c, pg_attribute a, pg_type t
      		WHERE c.relname = '$table'
      			AND a.attnum > 0
      			AND a.attrelid = c.oid
      			AND a.atttypid = t.oid
      		ORDER BY a.attnum";
      	$result = $database->GetArray($field_query);
      
      	if ($drop == 1)
      	{
      		$schema_create .= "DROP TABLE $table;$crlf";
      	} // end if
      
      	//
      	// Ok now we actually start building the SQL statements to restore the tables
      	//
      
      	$schema_create .= "CREATE TABLE $table($crlf";

		$i=0;
      	while ($i < count($result))
      	{
			$row = $result[$i];
      		//
      		// Get the data from the table
      		//
      		$sql_get_default = "SELECT d.adsrc AS rowdefault
      			FROM pg_attrdef d, pg_class c
      			WHERE (c.relname = '$table')
      				AND (c.oid = d.adrelid)
      				AND d.adnum = " . $row['attnum'];
      		$def_res = $database->GetArray($sql_get_default);
			if(isset($def_res[0])){
				$def_res = $def_res[0];
			}
      
      		if (!count($def_res))
      		{
      			unset($row['rowdefault']);
      		}
      		else
      		{
      			$row['rowdefault'] = @pg_result($def_res, 0, 'rowdefault');
      		}
      
      		if ($row['type'] == 'bpchar')
      		{
      			// Internally stored as bpchar, but isn't accepted in a CREATE TABLE statement.
      			$row['type'] = 'char';
      		}
      
      		$schema_create .= '	' . $row['field'] . ' ' . $row['type'];
      
      		if (eregi('char', $row['type']))
      		{
      			if ($row['lengthvar'] > 0)
      			{
      				$schema_create .= '(' . ($row['lengthvar'] -4) . ')';
      			}
      		}
      
      		if (eregi('numeric', $row['type']))
      		{
      			$schema_create .= '(';
      			$schema_create .= sprintf("%s,%s", (($row['lengthvar'] >> 16) & 0xffff), (($row['lengthvar'] - 4) & 0xffff));
      			$schema_create .= ')';
      		}
      
      		if (!empty($row['rowdefault']))
      		{
      			$schema_create .= ' DEFAULT ' . $row['rowdefault'];
      		}
      
      		if ($row['notnull'] == 't')
      		{
      			$schema_create .= ' NOT NULL';
      		}
      
      		$schema_create .= ",$crlf";
      
			$i++;
      	}
      	//
      	// Get the listing of primary keys.
      	//
      
      	$sql_pri_keys = "SELECT ic.relname AS index_name, bc.relname AS tab_name, ta.attname AS column_name, i.indisunique AS unique_key, i.indisprimary AS primary_key
      		FROM pg_class bc, pg_class ic, pg_index i, pg_attribute ta, pg_attribute ia
      		WHERE (bc.oid = i.indrelid)
      			AND (ic.oid = i.indexrelid)
      			AND (ia.attrelid = i.indexrelid)
      			AND	(ta.attrelid = bc.oid)
      			AND (bc.relname = '$table')
      			AND (ta.attrelid = i.indrelid)
      			AND (ta.attnum = i.indkey[ia.attnum-1])
      		ORDER BY index_name, tab_name, column_name ";
      	$result = $database->GetArray($sql_pri_keys);
      
      	$j=0;
      	while ( $j < count($result))
      	{
			$row = $result[$j];
      		if ($row['primary_key'] == 't')
      		{
      			if (!empty($primary_key))
      			{
      				$primary_key .= ', ';
      			}

      			$primary_key .= $row['column_name'];
      			$primary_key_name = $row['index_name'];
      
      		}
      		else
      		{
      			//
      			// We have to store this all this info because it is possible to have a multi-column key...
      			// we can loop through it again and build the statement
      			//
      			$index_rows[$row['index_name']]['table'] = $table;
      			$index_rows[$row['index_name']]['unique'] = ($row['unique_key'] == 't') ? ' UNIQUE ' : '';
				if(isset($index_rows[$row['index_name']]['column_names'])){
      				$index_rows[$row['index_name']]['column_names'] .= $row['column_name'] . ', ';
				}else{
					$index_rows[$row['index_name']]['column_names'] = $row['column_name'] . ', ';
				}
      		}
			$j++;
      	}
      
      	if (!empty($index_rows))
      	{
      		while(list($idx_name, $props) = each($index_rows))
      		{
      			$props['column_names'] = ereg_replace(", $", "" , $props['column_names']);
      			$index_create .= 'CREATE ' . $props['unique'] . " INDEX $idx_name ON $table (" . $props['column_names'] . ");$crlf";
      		}
      	}
      
      	if (!empty($primary_key))
      	{
      		$schema_create .= "	CONSTRAINT $primary_key_name PRIMARY KEY ($primary_key),$crlf";
      	}
      
      	//
      	// Generate constraint clauses for CHECK constraints
      	//
      	$sql_checks = "SELECT rcname as index_name, rcsrc
      		FROM pg_relcheck, pg_class bc
      		WHERE rcrelid = bc.oid
      			AND bc.relname = '$table'
      			AND NOT EXISTS (
      				SELECT *
      					FROM pg_relcheck as c, pg_inherits as i
      					WHERE i.inhrelid = pg_relcheck.rcrelid
      						AND c.rcname = pg_relcheck.rcname
      						AND c.rcsrc = pg_relcheck.rcsrc
      						AND c.rcrelid = i.inhparent
      			)";
      	$result = $database->GetArray($sql_checks);

      	//
      	// Add the constraints to the sql file.
      	//
		$k=0;
      	while ($k < count($result) && !empty($result))
      	{
			$row = $result[$k];
      		$schema_create .= '	CONSTRAINT ' . $row['index_name'] . ' CHECK ' . $row['rcsrc'] . ",$crlf";
			$k++;
      	}
      
      	$schema_create = ereg_replace(',' . $crlf . '$', '', $schema_create);
      	$index_create = ereg_replace(',' . $crlf . '$', '', $index_create);
      
      	$schema_create .= "$crlf);$crlf";
      
      	if (!empty($index_create))
      	{
      		$schema_create .= $index_create;
      	}
      
      	//
      	// Ok now we've built all the sql return it to the calling function.
      	//
      	return (stripslashes($schema_create));
      
      }
      
      //
      // This function returns the "CREATE TABLE" syntax for mysql dbms...
      //
      function get_table_def_mysql($table, $crlf)
      {
      	global $drop, $database;
      
      	$schema_create = "";
      	$field_query = "SHOW FIELDS FROM $table";
      	$key_query = "SHOW KEYS FROM $table";
      	$index = "";

      	//
      	// If the user has selected to drop existing tables when doing a restore.
      	// Then we add the statement to drop the tables....
      	//
      	if ($drop == 1)
      	{
      		$schema_create .= "DROP TABLE IF EXISTS $table;$crlf";
      	}
      
      	$schema_create .= "CREATE TABLE $table($crlf";
      
      	//
      	// Ok lets grab the fields...
      	//
      	$result = $database->GetArray($field_query);
      
		$i=0;
      	while ($i < count($result))
      	{
			$row = $result[$i];
      		$schema_create .= '	' . $row['Field'] . ' ' . $row['Type'];
      
      		if(!empty($row['Default']))
      		{
      			$schema_create .= ' DEFAULT \'' . $row['Default'] . '\'';
      		}
      
      		if($row['Null'] != "YES")
      		{
      			$schema_create .= ' NOT NULL';
      		}
      
      		if($row['Extra'] != "")
      		{
      			$schema_create .= ' ' . $row['Extra'];
      		}
      
      		$schema_create .= ",$crlf";
			$i++;
      	}
      	//
      	// Drop the last ',$crlf' off ;)
      	//
      	$schema_create = ereg_replace(',' . $crlf . '$', "", $schema_create);
      
      	//
      	// Get any Indexed fields from the database...
      	//
      	$result = $database->GetArray($key_query);

      
		$i=0;
      	while ($i < count($result))
      	{
			$row = $result[$i];
      		$kname = $row['Key_name'];
      
      		if(($kname != 'PRIMARY') && ($row['Non_unique'] == 0))
      		{
      			$kname = "UNIQUE|$kname";
      		}
      
      		if(isset($index[$kname]) && !is_array($index[$kname]))
      		{
      			$index[$kname] = array();
      		}
      
      		$index[$kname][] = $row['Column_name'];
			$i++;
      	}
      
      	while(list($x, $columns) = @each($index))
      	{
      		$schema_create .= ", $crlf";
      
      		if($x == 'PRIMARY')
      		{
      			$schema_create .= '	PRIMARY KEY (' . implode($columns, ', ') . ')';
      		}
      		elseif (substr($x,0,6) == 'UNIQUE')
      		{
      			$schema_create .= '	UNIQUE ' . substr($x,7) . ' (' . implode($columns, ', ') . ')';
      		}
      		else
      		{
      			$schema_create .= "	KEY $x (" . implode($columns, ', ') . ')';
      		}
      	}
      
      	$schema_create .= "$crlf);";
      
      	if(get_magic_quotes_runtime())
      	{
      		return(stripslashes($schema_create));
      	}
      	else
      	{
      		return($schema_create);
      	}
      
      } // End get_table_def_mysql
      
      
      //
      // This fuction will return a tables create definition to be used as an sql
      // statement.
      //
      //
      // The following functions Get the data from the tables and format it as a
      // series of INSERT statements, for each different DBMS...
      // After every row a custom callback function $handler gets called.
      // $handler must accept one parameter ($sql_insert);
      //
      //
      // Here is the function for postgres...
      //
      function get_table_content_postgresql($table, $handler)
      {
      	global $database;
      
      	//
      	// Grab all of the data from current table.
      	//
      
      	$result = $database->Execute("SELECT * FROM $table");
      	$rows = $database->GetArray("SELECT * FROM $table");

		$i_num_fields=0;
		if($result){
      		$i_num_fields = $result->FieldCount();
		}

      	for ($i = 0; $i < $i_num_fields; $i++)
      	{
			$fieldinfo = $result->FetchField($i);
      		$aryType[] = $fieldinfo->type;
      		$aryName[] = $fieldinfo->name;
      	}

      	$iRec = 0;

		$j = 0;
      	while($j < count($rows))
      	{
			$row = $rows[$j];
      		$schema_vals = '';
      		$schema_fields = '';
      		$schema_insert = '';
      		//
      		// Build the SQL statement to recreate the data.
      		//

      		for($i = 0; $i < $i_num_fields; $i++)
      		{
      			$strVal = $row[$aryName[$i]];
      			if (eregi("char|text|bool", $aryType[$i]))
      			{
      				$strQuote = "'";
      				$strEmpty = "";
      				$strVal = addslashes($strVal);
      			}
      			elseif (eregi("date|timestamp", $aryType[$i]))
      			{
      				if (empty($strVal))
      				{
      					$strQuote = "";
      				}
      				else
      				{
      					$strQuote = "'";
      				}
      			}
      			else
      			{
      				$strQuote = "";
      				$strEmpty = "NULL";
      			}
      
      			if (empty($strVal) && $strVal != "0")
      			{
      				$strVal = $strEmpty;
      			}
      
      			$schema_vals .= " $strQuote$strVal$strQuote,";
      			$schema_fields .= " $aryName[$i],";
      
      		}
      
      		$schema_vals = ereg_replace(",$", "", $schema_vals);
      		$schema_vals = ereg_replace("^ ", "", $schema_vals);
      		$schema_fields = ereg_replace(",$", "", $schema_fields);
      		$schema_fields = ereg_replace("^ ", "", $schema_fields);
      
      		//
      		// Take the ordered fields and their associated data and build it
      		// into a valid sql statement to recreate that field in the data.
      		//
      		$schema_insert = "INSERT INTO $table ($schema_fields) VALUES($schema_vals);";
      
      		$this->$handler(trim($schema_insert));
			$j++;
      	}
      
      	return(true);
      
      }// end function get_table_content_postgres...
      
      //
      // This function is for getting the data from a mysql table.
      //
      
      function get_table_content_mysql($table, $handler)
      {
      	global $database;
      
      	// Grab the data from the table.
      	$result = $database->GetArray("SELECT * FROM $table");
      
      	// Loop through the resulting rows and build the sql statement.
		$k=0;
      	if ($k < count($result))
      	{
			$row = $result[$k];
			$result2 = $database->Execute("SELECT * FROM $table");
      		$this->$handler("\n#\n# Table Data for $table\n#\n");
      		$field_names = array();
      
      		// Grab the list of field names.
      		$num_fields = $result2->fieldCount();
      		$table_list = '(';
      		for ($j = 0; $j < $num_fields; $j++)
      		{
				$fieldinfo = $result2->FetchField($j);
      			$field_names[$j] = $fieldinfo->name;
      			$table_list .= (($j > 0) ? ', ' : '') . $field_names[$j];
      			
      		}
      		$table_list .= ')';
      
      		do
      		{
				$row = $result[$k];
      			// Start building the SQL statement.
      			$schema_insert = "INSERT INTO $table $table_list VALUES(";
      
      			// Loop through the rows and fill in data for each column
      			for ($j = 0; $j < $num_fields; $j++)
      			{
      				$schema_insert .= ($j > 0) ? ', ' : '';
      
      				if(!isset($row[$field_names[$j]]))
      				{
      					//
      					// If there is no data for the column set it to null.
      					// There was a problem here with an extra space causing the
      					// sql file not to reimport if the last column was null in
      					// any table.  Should be fixed now :) JLH
      					//
      					$schema_insert .= 'NULL';
      				}
      				elseif ($row[$field_names[$j]] != '')
      				{
      					$schema_insert .= '\'' . addslashes($row[$field_names[$j]]) . '\'';
      				}
      				else
      				{
      					$schema_insert .= '\'\'';
      				}
      			}
      
      			$schema_insert .= ');';

      			$k++;

      			// Go ahead and send the insert statement to the handler function.
      			$this->$handler(trim($schema_insert));
  
      		}
      		while ($k < count($result));
      	}
      
      	return(true);
      }
      
      function output_table_content($content)
      {
      	echo $content ."\n";
      	return;
      }

}

$backup = new Backup();

?>