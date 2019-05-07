<?php

/**
* Import .csv file into huskeliste via hardcoded prefix
*/

error_reporting(E_ALL);

require_once dirname(__FILE__) . '/../../settings.php';
require_once OPENLIST_CLASSES_PATH . '/Dev.php';
require_once OPENLIST_CLASSES_PATH . '/DB.php';

if (!php_sapi_name() == 'cli') {
  import_log("Run from CLI plz");
  exit;
}

// Get work done:
if (empty($argv[1]))  {
  import_log("Missing argument: import file");
  exit;
}
do_import($argv[1]);
import_log("Done");
exit;


function do_import($filename) {
  require_once dirname(__FILE__) . '/settings.php';

  import_log("Start import");

  if (!file_exists($filename))  {
    import_log("File not found: $filename");
    exit;
  }

  import_log("Importing file:" . $filename);

  $handle = fopen($filename, "r");

  if ($handle === FALSE) {
    import_log("Cannot open file");
    exit;
  }

  while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $patronid = trim($data[1]);
    $record = $data[2];

    // Skip bad patron ids
    if (!is_numeric($patronid)) { 
      import_log("Skipping $patronid");
      continue; 
    }     
//    if ($patronid != "13736499") { continue; }

    // Import the single record
    $owner = hash('sha512', $prefix . $patronid);
    import_log("$patronid with  $record for $owner");
    $lid = get_remember($owner,$librarycode);
    insert_element($lid,$record,$librarycode);
    
  }
  fclose($handle);

}

function insert_element($lid, $record, $librarycode) {
  import_log("Insert element: $record into $lid");

  $data = array(
    "value" => $record,
    "type" => "ting_object",
  );

  DB::q('
    INSERT INTO elements
    (list_id,data,weight,created,modified,status,previous,library_code)
    VALUES
    ("@lid","@data",@weight,@created,@modified,@status,0,"@library_code")
    ',
    array(
      '@lid' => $lid,
      '@data' => serialize($data),
      '@weight' => 1,
      '@created' => "NOW()",
      '@modified' => "UNIX_TIMESTAMP()",
      '@status' => 1,
      '@library_code' => $librarycode,
    )
  );
  $eid = DB::insert_id();
  import_log("Imported $eid");
  return $eid;
}

function get_remember($owner,$librarycode) {
  $result = DB::q('
    SELECT list_id 
    FROM lists
    WHERE type="remember" AND owner="@pattern" 
    LIMIT 1
    ',array('@pattern' => $owner)
  );

  $row = $result->fetch_row();
  $lid = $row[0];
  
  // Create missing remember list
  if (!is_numeric($lid)) {
    DB::q('
      INSERT INTO lists
      (owner,title,created,modified,status,type,data,library_code)
      VALUES
      ("@owner","@title",@created,@modified,@status,"@type","@data","@library_code")
      ',
      array(
        '@owner' => $owner,
        '@title' => "huskeliste",
        '@created' => "NOW()",
        '@modified' => "UNIX_TIMESTAMP()",
        '@status' => 1,
        '@type' => "remember",
        '@data' => "s:0:\"\";",
        '@library_code' => $librarycode
      )
    );
    $lid = DB::insert_id();
  }
  return $lid;
}

function import_log($msg) {
  echo "$msg\n";
}

