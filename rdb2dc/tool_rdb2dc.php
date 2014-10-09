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
define("DEBUG_MODE", true);

 
class RDB2DC {

  private $db;
  private $outfile;

  
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
   * query details for a specific publication
   * then call a function to perform output/formatting
   */
  function query_pub_details($pub_id, $sql, $format_function) {
    try {
      $stmt = $this->db->prepare($sql);
      $stmt->bindParam(1, $pub_id);

      if ($stmt->execute()) {
        while ($row = $stmt->fetch()) {          
          call_user_func(array($this, $format_function), $row);
        }
      }      
    }
    catch (PDOException $pe) {
      echo "PDOException error: " . $pe->getMessage();
    }    
  }  

  /****************************************************************************/
  // General File Functions

  // create the output dublin core xml file for this publication
  function file_new($pub_id) {
    if (!file_exists("import/" . $pub_id)) {
      mkdir("import/" . $pub_id);
    }
      
    $this->outfile = fopen("import/" . $pub_id . "/dublin_core.xml", "w");
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
    if (file_exists("pdfs/" . $filename)) {
      if (DEBUG_MODE) echo "[info] Moving file " . $filename . "\n";
      rename("pdfs/" . $filename, "import/" . $pub_id . "/" . $filename);
    }
  }

  /**
   * DSpace Batch Import format requires a "contents" file
   * specifying which additional files belong to this item bundle
   */
  function write_contents($pub_id, $filename) {
    $contents = fopen("import/" . $pub_id . "/contents", "w");
    fwrite($contents, $filename . "\t" . "bundle:ORIGINAL\n");
    fclose($contents);
  }
  
  /**
   * Creates a dcvalue xml tag, formatted like this:
   * <dcvalue element="foo" qualifier="bar">baz</dcvalue>
   * <dcvalue element="foo">bar</dcvalue>
   */
  function write_dcvalue($element, $qualifier, $value) {

    // skip on empty values
    if ($value == "" || $value == "0") return;

    // note tab indentation 1 for the xml document
    fwrite($this->outfile, "	<dcvalue ");
    fwrite($this->outfile, "element=\"" . trim($element) . "\"");
    if (trim($qualifier) != "") {
      fwrite($this->outfile, " qualifier=\"" . trim($qualifier) . "\"");
    }
    fwrite($this->outfile, ">");
	
    // XML requires escaping <, >, &, etc.
    $xmlvalue = htmlspecialchars(trim($value));

    fwrite($this->outfile, $xmlvalue);
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
    $this->query_pub_details($pub_id, $sql, "RDB2DC::format_journal");
  }

  function format_subject($subject) {
    $this->write_dcvalue("subject", "", $subject['name']);
  }

  function query_subjects($pub_id) {
    $sql = "select name " .
           "from pubs_category inner join pubs_main_cat_lnk lnk on lnk.f_category_id = pubs_category.category_id " .
           "where f_pub_id = ?";
    $this->query_pub_details($pub_id, $sql, "RDB2DC::format_subject");
  }

  function format_keyword($keyword) {
    $this->write_dcvalue("subject", "keyword", $keyword['name']);
  }

  function query_keywords($pub_id) {
    $sql = "select name " .
           "from pubs_tag inner join pubs_main_tag_lnk lnk on lnk.f_tag_id = pubs_tag.tag_id " .
           "where f_pub_id = ?";
    $this->query_pub_details($pub_id, $sql, "RDB2DC::format_keyword");
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
    $this->query_pub_details($pub_id, $sql, "RDB2DC::format_author");
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

    if (DEBUG_MODE) {
      echo("[info] " . $id . " " . $pub['title'] . "\n");
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
    $this->query_pub_details("", $sql, "RDB2DC::format_pub");
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
