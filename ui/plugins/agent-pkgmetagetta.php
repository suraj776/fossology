<?php
/***********************************************************
 Copyright (C) 2008 Hewlett-Packard Development Company, L.P.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

/*************************************************
 Restrict usage: Every PHP file should have this
 at the very beginning.
 This prevents hacking attempts.
 *************************************************/
global $GlobalReady;
if (!isset($GlobalReady)) { exit; }

class agent_pkgmetagetta extends FO_Plugin
{
  public $Name       = "agent_pkgmetagetta";
  public $Title      = "Schedule Metadata Analysis";
  // public $MenuList   = "Jobs::Agents::Metadata Analysis";
  public $Version    = "1.0";
  public $Dependency = array("db");
  public $DBaccess   = PLUGIN_DB_ANALYZE;

  /***********************************************************
   RegisterMenus(): Register additional menus.
   ***********************************************************/
  function RegisterMenus()
    {
    if ($this->State != PLUGIN_STATE_READY) { return(0); } // don't run
    menu_insert("Agents::" . $this->Title,0,$this->Name);
    }

  /*********************************************
   AgentCheck(): Check if the job is already in the
   queue.  Returns:
     0 = not scheduled
     1 = scheduled but not completed
     2 = scheduled and completed
   *********************************************/
  function AgentCheck($uploadpk)
  {
    global $DB;
    $SQL = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job ON job_upload_fk = '$uploadpk' AND job_pk = jq_job_fk AND jq_type = 'pkgmetagetta';";
    $Results = $DB->Action($SQL);
    if (empty($Results[0]['jq_pk'])) { return(0); }
    if (empty($Results[0]['jq_endtime'])) { return(1); }
    return(2);
  } // AgentCheck()

  /*********************************************
   AgentAdd(): Given an uploadpk, add a job.
   $Depends is for specifying other dependencies.
   $Depends can be a jq_pk, or an array of jq_pks, or NULL.
   Returns NULL on success, string on failure.
   *********************************************/
  function AgentAdd ($uploadpk,$Depends=NULL,$Priority=0)
  {
    global $DB;
    /* Get dependency: "pkgmetagetta" require "unpack". */
    $SQL = "SELECT jq_pk FROM jobqueue
	    INNER JOIN job ON job.job_upload_fk = '$uploadpk'
	    AND job.job_pk = jobqueue.jq_job_fk
	    WHERE jobqueue.jq_type = 'unpack';";
    $Results = $DB->Action($SQL);
    $Dep = $Results[0]['jq_pk'];
    if (empty($Dep))
	{
	global $Plugins;
	$Unpack = &$Plugins[plugin_find_id("agent_unpack")];
	$rc = $Unpack->AgentAdd($uploadpk);
	if (!empty($rc)) { return($rc); }
	$Results = $DB->Action($SQL);
	$Dep = $Results[0]['jq_pk'];
	if (empty($Dep)) { return("Unable to find dependent job: unpack"); }
	}
    $Dep = array($Dep);
    if (is_array($Depends)) { $Dep = array_merge($Dep,$Depends); }
    else if (!empty($Depends)) { $Dep[1] = $Depends; }

    /* Prepare the job: job "Meta Analysis" */
    $jobpk = JobAddJob($uploadpk,"Meta Analysis",$Priority);
    if (empty($jobpk) || ($jobpk < 0)) { return("Failed to insert job record"); }

    /* "pkgmetagetta" needs to know the attribkey for 'Processed' */
    $SQL = "SELECT key_pk FROM key
	WHERE key_name='Processed' AND key_parent_fk IN
	(SELECT key_pk FROM key WHERE key_name='pkgmeta');";
    $Results = $DB->Action($SQL);
    $attribkey = $Results[0]['key_pk'];
    if (empty($attribkey)) { return("Pkgmetagetta not installed."); }

    /* Performance note: The SELECT of files can be very expensive.
       Store results in a temp table to cut the cost. */

    /* Before starting, make sure the temp table does not exist. */
    $TempTable = "metaanalysis_" . $uploadpk; /* must be lowercase */
    $SQL = "SELECT * FROM pg_tables WHERE tablename='$TempTable';";
    $Results = $DB->Action($SQL);
    if (!empty($Results[0]['tablename']))
	{
	$DB->Action("DROP TABLE $TempTable;");
	}

    /** jqargs wants EVERY pfile in this upload that hasn't been processed
        by pkgmetagetta. **/
    $jqargs = "SELECT DISTINCT(pfile_pk) as Akey,
	pfile_sha1 || '.' || pfile_md5 || '.' || pfile_size AS A
	INTO $TempTable
    FROM pfile left outer join attrib on (attrib_key_fk='$attribkey' 
      AND attrib.pfile_fk=pfile_pk and attrib_value is null)
    INNER join uploadtree on (upload_fk='$uploadpk') and uploadtree.pfile_fk=pfile_pk;";

    /* Add job: job has jobqueue item "sqlagent" */
    /** sqlagent does not like newlines! **/
    $jqargs = str_replace("\n"," ",$jqargs);
    $jobqueuepk = JobQueueAdd($jobpk,"sqlagent",$jqargs,"no","",$Dep);
    if (empty($jobqueuepk)) { return("Failed to insert first sqlagent into job queue"); }

    /* Add job: job has jobqueue item "pkgmetagetta" */
    $jqargs = "SELECT *, '$TempTable' AS table FROM $TempTable LIMIT 5000;";
    $jobqueuepk = JobQueueAdd($jobpk,"pkgmetagetta",$jqargs,"yes","a",array($jobqueuepk));
    if (empty($jobqueuepk)) { return("Failed to insert pkgmetagetta into job queue"); }

    /* Add job: job has jobqueue item "sqlagent" to remove the temporary table */
    $jqargs = "DROP TABLE $TempTable;";
    $jobqueuepk = JobQueueAdd($jobpk,"sqlagent",$jqargs,"no","",array($jobqueuepk));
    if (empty($jobqueuepk)) { return("Failed to insert second sqlagent into job queue"); }

    return(NULL);
  } // AgentAdd()

  /*********************************************
   Output(): Generate the text for this plugin.
   *********************************************/
  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY) { return; }
    global $DB;
    $V="";
    switch($this->OutputType)
    {
      case "XML":
	break;
      case "HTML":
	/* If this is a POST, then process the request. */
	$uploadpk = GetParm('upload',PARM_INTEGER);
	if (!empty($uploadpk))
	  {
	  $rc = $this->AgentAdd($uploadpk);
	  if (empty($rc))
	    {
	    /* Need to refresh the screen */
	    $V .= PopupAlert('Analysis added to job queue');
	    }
	  else
	    {
	    $V .= PopupAlert("Scheduling failed: $rc");
	    }
	  }

	/* Get list of projects that are not scheduled for uploads */
	$SQL = "SELECT upload_pk,upload_desc,upload_filename
		FROM upload
		WHERE upload_pk NOT IN
		(
		  SELECT upload_pk FROM upload
		  INNER JOIN job ON job.job_upload_fk = upload.upload_pk
		  INNER JOIN jobqueue ON jobqueue.jq_job_fk = job.job_pk
		    AND job.job_name = 'Meta Analysis'
		    AND jobqueue.jq_type = 'pkgmetagetta'
		    ORDER BY upload_pk
		)
		ORDER BY upload_desc,upload_filename;";
	$Results = $DB->Action($SQL);
	if (empty($Results[0]['upload_pk']))
	  {
	  $V .= "All uploaded files are already analyzed, or scheduled to be analyzed.";
	  }
	else
	  {
	  /* Display the form */
	  $V .= "Metadata analysis extracts meta data from RPM and DEB files.<P />\n";
	  $V .= "<form method='post'>\n"; // no url = this url
	  $V .= "Select an uploaded file for analysis.\n";
	  $V .= "Only uploads that are not already scheduled can be scheduled.\n";
	  $V .= "<p />\nAnalyze: <select name='upload'>\n";
	  foreach($Results as $Row)
	    {
	    if (empty($Row['upload_pk'])) { continue; }
	    if (empty($Row['upload_desc'])) { $Name = $Row['upload_filename']; }
	    else { $Name = $Row['upload_desc'] . " (" . $Row['upload_filename'] . ")"; }
	    $V .= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
	    }
	  $V .= "</select><P />\n";
	  $V .= "<input type='submit' value='Analyze!'>\n";
	  $V .= "</form>\n";
	  }
	break;
      case "Text":
	break;
      default:
	break;
    }
    if (!$this->OutputToStdout) { return($V); }
    print("$V");
    return;
  }
};
$NewPlugin = new agent_pkgmetagetta;
$NewPlugin->Initialize();
?>
