<?php 
/******************************************************************************
 * Converter for a scholarly works publication relational database
 * to Dublin Core files for import into DSpace
 * @author Clint Bellanger
 ******************************************************************************/
include_once('inc_db.php');

// CONFIGURATION
error_reporting(E_ALL);
ini_set('display_errors', '1');

// verbose debug mode outputs additional info messages
define("VERBOSE", false);

// test mode does not move the PDFs
define("TEST_MODE", true);

// TODO: change this to a lookup of the length of the largest pub_id?
define("FOLDER_DIGITS", 4);
 
class RDB2DC {

  private $db;
  private $outfile;

  function escape_filename($filename) {
    return str_replace(array(' ', '(', ')'), array('\ ', '\(', '\)'), $filename);
  }
  
  /****************************************************************************/
  // General Database Functions

  // connect to the mysql database specified in inc_db.php
  function db_connect() {
    try {
      $this->db = new PDO(OPT_DB_CONN, OPT_DB_USER, OPT_DB_PASS);
    }
    catch (PDOException $pe) {
      echo "PDOException error: " . $pe->getMessage();
    }
  }

  // close database connection
  function db_disconnect() {
    $this->db = null;
  }
  
  /**
   *
   */
  function get_recordset($sql, $uid = NULL) {

    $resultset = array();

    try {
      $stmt = $this->db->prepare($sql);

      if (!is_null($uid)) {
        $stmt->bindParam(1, $uid);
      }

      if ($stmt->execute()) {
        while ($row = $stmt->fetch()) {          
          $resultset[] = $row;
        }
      }      
    }
    catch (PDOException $pe) {
      echo "PDOException error: " . $pe->getMessage();
    }

    return $resultset;
  }  

  /****************************************************************************/
  // General File Functions

  function folder_name($pub_id) {
    return str_pad($pub_id, FOLDER_DIGITS, '0', STR_PAD_LEFT);
  }

  // create the output dublin core xml file for this publication
  function file_new($pub_id) {
    $output_dir = "import/" . $this->folder_name($pub_id);

    if (!file_exists($output_dir)) {
      mkdir($output_dir);
    }
      
    $this->outfile = fopen($output_dir . "/dublin_core.xml", "w");
  }

  // finished writing this dublin core xml file
  function file_close() {
    fclose($this->outfile);
  }

  /**
   * Move the collected PDFs from one folder
   * into the individual item bundle folders
   */ 
  function move_pdf($pub_id, $filename) {
    $file_esc = $this->escape_filename($filename);

    if (file_exists("pdfs/" . $filename) && $filename != "") {
      if (VERBOSE) echo "[info] Moving file " . $filename . "\n";

      if (!TEST_MODE) {
        rename("pdfs/" . $filename, "import/" . $this->folder_name($pub_id) . "/" . $filename);
      }

    }
  }

  /**
   * DSpace Batch Import format requires a "contents" file
   * specifying which additional files belong to this item bundle
   */
  function write_contents($pub_id, $filename) {
    $contents = fopen("import/" . $this->folder_name($pub_id) . "/contents", "w");
    fwrite($contents, $filename . "\t" . "bundle:ORIGINAL\n");
    fclose($contents);
  }

  /**
   * escape and encode a string for an xml element
   */
  function clean_value($value) {

    $working = trim($value);

    // Removes any invalid unicode characters
    $working = mb_convert_encoding($working, 'UTF-8', 'UTF-8');

    // XML documents can't have control characters
    $working = preg_replace('/[[:cntrl:]]/', '', $working);

    // XML strings need escaped special characters
    $filtered = htmlspecialchars($working);

    return $filtered;
  }

  
  /**
   * Creates a dcvalue xml tag, formatted like this:
   * <dcvalue element="foo" qualifier="bar">baz</dcvalue>
   * <dcvalue element="foo">bar</dcvalue>
   */
  function write_dcvalue($element, $qualifier, $value) {

    // TODO: standard implementation with DOMDocument::createTextNode()?

    // skip on empty values
    if ($value == "" || $value == "0") return;

    // note tab indentation 1 for the xml document
    fwrite($this->outfile, "	<dcvalue ");
    fwrite($this->outfile, "element=\"" . trim($element) . "\"");
    if (trim($qualifier) != "") {
      fwrite($this->outfile, " qualifier=\"" . trim($qualifier) . "\"");
    }
    fwrite($this->outfile, ">");

    $value_xml = $this->clean_value($value);

    fwrite($this->outfile, $value_xml);
    fwrite($this->outfile, "</dcvalue>\n");
  }
  
  /****************************************************************************/
  // Core Business Logic
  
  function format_journal($journal) {
    
    $this->write_dcvalue("relation", "ispartof", $journal['journal']);
    $this->write_dcvalue("citation", "volume", $journal['volume']);

    $this->write_dcvalue("citation", "spage", $journal['spage']);
    $this->write_dcvalue("citation", "epage", $journal['epage']);
    $this->write_dcvalue("format", "extent", $journal['extent']);
  }

  function query_journals($pub_id) {
    $sql = "select journal, volume, spage, epage, extent " .
           "from pubs_journal_new " .
           "where pub_id = ?;";
    $journals = $this->get_recordset($sql, $pub_id);
    foreach($journals as $journal) {
      $this->format_journal($journal);
    }
  }

  function format_subject($subject) {
    $this->write_dcvalue("subject", "", $subject['name']);
  }

  function query_subjects($pub_id) {
    $sql = "select name " .
           "from pubs_category inner join pubs_main_cat_lnk lnk on lnk.f_category_id = pubs_category.category_id " .
           "where f_pub_id = ?";
    $subjects = $this->get_recordset($sql, $pub_id);
    foreach($subjects as $subject) {
      $this->format_subject($subject);
    }
  }

  function format_keyword($keyword) {
    $this->write_dcvalue("subject", "keyword", $keyword['name']);
  }

  function query_keywords($pub_id) {
    $sql = "select name " .
           "from pubs_tag inner join pubs_main_tag_lnk lnk on lnk.f_tag_id = pubs_tag.tag_id " .
           "where f_pub_id = ?";
    $keywords = $this->get_recordset($sql, $pub_id);
    foreach($keywords as $keyword) {
      $this->format_keyword($keyword);
    }
  }

  function format_author($author) {
    $fullname = $author['lname'];
    if ($author['fname'] != "") {
      $fullname = $fullname . ", " . $author['fname'];
    }    
    $this->write_dcvalue("creator", "", $fullname);
  }

  function query_authors($pub_id) {
    $sql = "select fname, lname " . 
           "from pubs_author inner join pubs_main_auth_lnk lnk on lnk.f_author_id = pubs_author.author_id " .
           "where f_pub_id = ?";

    $authors = $this->get_recordset($sql, $pub_id);
    foreach($authors as $author) {
      $this->format_author($author);
    }
  }
 
  function write_xml_header() {
    fwrite($this->outfile, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($this->outfile, "<dublin_core>\n");
  }

  function write_xml_footer() {
    fwrite($this->outfile, "</dublin_core>\n");
  }

  /**
   * $pub is a single rowset from the main publications table
   * Lookup additional info for this publication and write
   * the document to an xml file
   */
  function format_pub($pub) {

    $id = $pub['pub_id'];

    if (VERBOSE) {
      echo("[info] " . $id . " " . $pub['title'] . "\n");
    }

    // abort if PDF is missing
    if (!file_exists("pdfs/" . $pub['pub_file']) || $pub['pub_file'] == "") {
      echo "[warning] Missing PDF, skipping. pub_id=" . $id . ", file=" . $pub['pub_file'] . "\n";
      return;
    }

    // prepare for DSpace Batch Import Format
    $this->file_new($id);
    $this->move_pdf($id, $pub['pub_file']);
    $this->write_contents($id, $pub['pub_file']);

    $this->write_xml_header(); 

    // publication specific metadata
    $this->write_dcvalue("title", "", $pub['title']);
    $this->write_dcvalue("description", "abstract", $pub['abstract']);
    $this->write_dcvalue("date", "created", $pub['year']);

    // repeating, controlled metadata
    $this->query_authors($id);
    $this->query_journals($id);
    $this->query_subjects($id);
    $this->query_keywords($id);

    $this->write_xml_footer();

    $this->file_close();

  }
   
  function query_pubs() {  
    $sql = "select pub_id, title, abstract, year, pub_file from pubs_main;";
    $publications = $this->get_recordset($sql);
    foreach($publications as $publication) {
      $this->format_pub($publication);
    }
  }
  
  function export_all() {
    $this->db_connect();
    $this->query_pubs();
    $this->db_disconnect();
  }
  
} 
/******************************************************************************/

// main/init
$r = new RDB2DC;
$r->export_all();

?>
