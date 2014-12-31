<?php

// Force a debugging mode regardless the settings in the site administration
// @error_reporting(1023);  // NOT FOR PRODUCTION SERVERS!
@ini_set('display_errors', '1'); // NOT FOR PRODUCTION SERVERS!
$CFG->debug = 32767;         // DEBUG_DEVELOPER // NOT FOR PRODUCTION SERVERS!
// for Moodle 2.0 - 2.2, use:  $CFG->debug = 38911;  
$CFG->debugdisplay = true;   // NOT FOR PRODUCTION SERVERS!

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Repository to access Emailed files via IMAP
 *
 * @package    repository_emailed_files
 * @copyright  2014 Robert Schrenk (http://www.schrenk.cc)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @todo       exceptions, pop3-support, inline-attachments
 */

class repository_emailed_files extends repository {
    /**
     * Constructor of emailed_files plugin
     *
     * @param int $repositoryid
     * @param stdClass $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        global $CFG;
        parent::__construct($repositoryid, $context, $options);
        
        $this->debug = false;
        
        if($this->debug) error_log("Construct");

		$this->host = get_config('emailed_files','host');
		$this->port = get_config('emailed_files','port');
		// Enable configuration for other protocols if you want to implement POP3
		$this->protocol = 'imap'; // get_config('emailed_files','protocol');
		$this->box = get_config('emailed_files','box');
		$this->user = get_config('emailed_files','user');
		$this->pass = get_config('emailed_files','pass');
		$this->use_ssl = (get_config('emailed_files','use_ssl')==1)?true:false;
		$this->validate_cert = (get_config('emailed_files','validate_cert')==1)?true:false;
		$this->purge_age_days = get_config('emailed_files','purge_age_days');

		$this->path = "/service=".$this->protocol;
		if($this->use_ssl) $this->path .= "/ssl";
		if($this->use_ssl && !$this->validate_cert) $this->path .= "/novalidate-cert";

		$this->imap = "{".$this->host.":".$this->port.$this->path."}".$this->box;
    }

    /**
     * Connect to mailbox
     *
     * @throws moodle_exception when connection can not be established
     * @return IMAP-Connection to mailbox
     */
    private function connect_mailbox() {
        $mailbox = imap_open(imap_utf7_encode($this->imap),$this->user,$this->pass);
        if(!$mailbox)
            throw new moodle_exception('connectionerror', 'repository', $mailbox, '', imap_last_error() );
        if($this->debug) error_log("Connected to Mailbox ".$this->imap." using username ".$this->user);
		return $mailbox;
    }

    /**
     * Get emailed files
     *
     * @param string $filterpath is email_number to open
     * @param int $page page number for pagination
     * @return array list of files
     */
    public function get_listing($filterpath = '', $page = '1') {
		$mailbox = $this->connect_mailbox();

		$this->find_emails_for_deletion($mailbox);
		$emails = $this->find_emails_of_user($mailbox);
		
		$list = array();
		
		$list['list'] = array();
		$list['path'] = array();
		$list['path'][0] = new stdClass;
		$list['path'][0]->name = "root";
		$list['path'][0]->path = "";
		$list['manage'] = '';

		if(count($emails)>0) {
			rsort($emails);

			for($i=0;$i<count($emails);$i++) {
				$email_number = $emails[$i];			
				$overview = imap_fetch_overview($mailbox,$email_number,0);
				if($overview[0]->subject=="")
					$overview[0]->subject = "Empty Subject";
				$structure = imap_fetchstructure($mailbox, $email_number);
				if($this->debug) error_log("   - Structure ".$email_number);
			
				$attachments = $this->read_object($structure);
				if(count($attachments)>0) {
					$path = new stdClass; $path->name = $overview[0]->subject;
					$path->path = $email_number;
					$list['path'][] = $path;
					
					if($filterpath==$email_number) 
						$list['list'] = $attachments;
					elseif($filterpath=='') {
						$folder = new stdClass; $folder->title = $overview[0]->subject;
						$folder->date = strtotime($overview[0]->date); $folder->size = 0;
						$folder->children = array();

						for($z=0;$z<count($attachments);$z++) {
							$attachments[$z]->date = $folder->date;
							$folder->size = $folder->size+$attachments[$z]->size;
							$folder->children[] = $attachments[$z];
						}
						$list['list'][] = $folder;
					}
				}
			}
			imap_close($mailbox);
		} else {
			imap_close($mailbox);
			return false;
		}
        return $list;
    }

    /**
	Find Mails for current moodle user
	@param resource $mailbox mailbox reference of imap_open
	@return array of email numbers matching search
     */
    private function find_emails_of_user($mailbox) {
        global $USER;
		$emails = array();
		switch($this->protocol) {
			case "imap":
				$emails = imap_search($mailbox,"FROM \"".$USER->email."\"",SE_FREE,"UTF8");
			break;
			case "pop3":
				// INFO: POP3 may be implemented, but is not supported for now
				$allmails = imap_fetch_overview($mailbox,"1:*");
				foreach($allmails as $mail){
					if($mail->from==$USER->email || strpos($mail->from,"<".$USER->email.">")>0)
						$emails[] = $mail->msgno;
				}
			break;
		}
		if($this->debug) {
			if(!$emails) error_log("NO Emails for ".$USER->email." found");
			else error_log("Found ".count($emails)." Emails for user ".$USER->email);
		}
		return $emails;	
    }
    /**
	Find Mails older than $this->purge_age_days and delete them
	@param resource $mailbox mailbox reference of imap_open
     */
    private function find_emails_for_deletion($mailbox) {
        global $USER;
		if($this->purge_age_days==0) return;

		$emails = array();

		$del_time = mktime(date("H"),date("i"),date("s"),date("n"),date("j")-$this->purge_age_days,date("Y"));
		switch($this->protocol) {
			case "imap":
				$emails = imap_search($mailbox,"BEFORE \"".date("d-M-Y",$del_time)."\"");
			break;
			case "pop3":
				// INFO: POP3 may be implemented, but is not supported for now
				$allmails = imap_fetch_overview($mailbox,"1:*",FT_UID);
				foreach($allmails as $mail) {
					if(strtotime($mail->date)<$del_time)
						$emails[] = $mail->msgno;
				}
			break;
		}
		if(!$emails) {
			// imap_search returns false if no results
			if($this->debug) error_log("No deprecated emails to delete");
			return;
		}
		if(count($emails)>0) {
			if($this->debug) error_log("Deleting ".count($emails)." deprecated emails");
			foreach($emails as $email_number)
				imap_delete($mailbox,$email_number);
			imap_expunge($mailbox);
		}
    }
    /**
    ** @param o email structure to search within, if attachments are found they
    **			are collected in an array and are given the imap section number
    **			according to imap specification
    ** @return	array containing attachment-objects
    */
    private function read_object($o) {
    	$attachments = array();
    	if(isset($o->parts)) {
    		if(isset($o->parts[1])) {
    			if(isset($o->parts[1]->parts)) {
					$nr = 2;
					foreach($o->parts[1]->parts AS $part) {
						$a = $this->read_part($part);
						if($a!=null) {
							$a->source = $mailnr."_2.".$nr++."_".$part->encoding;
							$attachments[] = $a;
						}
					}
    			} else {
					$nr = 2;
					foreach($o->parts AS $part) {
						$a = $this->read_part($part);
						if($a!=null) {
							$a->source = $mailnr."_".$nr++."_".$part->encoding;
							$attachments[] = $a;
						}
					}
    			}
    		}
    	}
    	return $attachments;
    }
    
    /**
    ** @param object part	Message-Part Object containing the information 
    **						of an attachment
    ** @return object for attachment or null
    **/
	private function read_part($part) {
		$att = new stdClass;
		$att->title = '';
		$att->size = $part->bytes;
		$att->thumbnail = null;
		$att->realthumbnail = null;
		$att->thumbnail_height = 64;
		$att->thumbnail_width = 64;
		$att->author = $GLOBALS["USER"]->email;

		if($part->ifdparameters)
			foreach($part->dparameters as $param)
				if(strtolower($param->attribute)=='filename') {
					$att->title = $param->value;
				}
		if($part->ifparameters)
			foreach($part->parameters as $param)
				if(strtolower($param->attribute)=='name') {
					$att->title = $param->value;
				}				

		if($att->title!="") {
			if($this->debug) error_log("      - Attachment found ".$att->title);
			return $att;
		} else return null;
	}
    
    /*
    error_log(print_r($o,1));
    return;
    	if(isset($o->subtype)) {
			if($this->debug) error_log("      - Type is ".$o->subtype);
			switch(strtolower($o->subtype)) {
				case "alternative":
				case "mixed":
					// Search for sub-parts
					if($this->debug) error_log("     - Type mixed / alternative, going deeper");
					$this->read_object($o->parts);
				break;
				default:
					if($this->debug) error_log("     - Part to search for attachments");
					$part = $o;
					// Analyze part of this object
					$att = array();
					$att['is_attachment'] = false;
					$att['filename'] = '';
					$att['name'] = '';
					$att['attachment'] = '';
					$att['structure_number'] = ''; // 2 or 2.3
					$att['bytes'] = $part->bytes;
		
					if($part->ifdparameters)
						foreach($part->dparameters as $param)
							if(strtolower($param->attribute)=='filename') {
								$att['is_attachment'] = true;
								$att['filename'] = $param->value;
							}
					if($part->ifparameters)
						foreach($part->parameters as $param)
							if(strtolower($param->attribute)=='name') {
								$att['is_attachment'] = true;
								$att['filename'] = $param->value;
							}				

					if($att["is_attachment"]) {
						if($this->debug) error_log("      - Attachment found ".$att["filename"]);
						//.print_r($att,true));
						$this->attachments[] = $att;
					} else if($this->debug) error_log("      - No Attachment");
					if(isset($o->parts)) {
						if($this->debug) error_log("      - There are still sub-parts - going deeper");
						$this->read_object($o->parts);
					}
			}
		} else {
			if($this->debug) error_log("     - Looping through ".count($o)." items");
			for($i=0;$i<count($o);$i++)
				$this->read_object($o[$i]);
		}
		*/
    //}   

    /**
     * Downloads a file from external repository and saves it in temp dir
     *
     * @throws moodle_exception when file could not be downloaded
     *
     * @param string $reference containing emailnumber + _ + structurenumber + _ + encoding
     * @param string $saveas filename (without path) to save the downloaded file in the
     * temporary directory, if omitted or file already exists the new filename will be generated
     * @return array with elements:
     *   path: internal location of the file
     */
    public function get_file($reference, $saveas = '') {
        global $CFG;
        $ref = explode("_",$reference);
        $saveas = $this->prepare_file($saveas);

		$email_number = $ref[0];
		$structure_number = $ref[1];
		$encoding = $ref[2];

		$mailbox = $this->connect_mailbox();
		$structure = imap_fetchstructure($mailbox, $email_number);
		//$this->read_object($structure);
		
		if($this->debug) error_log("     - Reading Mail#".$email_number.", Part ".$structure_number);
		//error_log(print_r($structure,1));
		$attachment = imap_fetchbody($mailbox, $email_number, $structure_number, FT_PEEK);

		/* 3 = BASE64 encoding */
		if($encoding == 3) { 
			$attachment = base64_decode($attachment);
		}
		/* 4 = QUOTED-PRINTABLE encoding */
		if($encoding == 4) { 
			$attachment = quoted_printable_decode($attachment);
		}

		if(strlen($attachment)==0)
			throw new moodle_exception('cannotdownload', 'repository');

		$fp = fopen($saveas, "w+");
		fwrite($fp, $attachment);
		fclose($fp);
		imap_close($mailbox);
		if($this->debug) error_log("     - Downloaded size: ".strlen($attachment));
		return array('path'=>$saveas); //, 'url'=>'');
    }
    /**
     * Set email option
     * @param array $options
     * @return mixed
     */
    public function set_option($options = array()) {
		$str_values = array('host','protocol','box','user','pass');
		$int_values = array('port','purge_age_days');
		$bool_values = array('use_ssl','validate_cert');

		for($i=0;$i<count($str_values);$i++) {
			if(!empty($options[$str_values[$i]]))
				set_config($str_values[$i],trim($options[$str_values[$i]]),'emailed_files');
			unset($options[$str_values[$i]]);
		}
		for($i=0;$i<count($int_values);$i++) {
			if(!empty($options[$int_values[$i]]))
				set_config($int_values[$i],(int)trim($options[$int_values[$i]]),'emailed_files');
			unset($options[$int_values[$i]]);
		}
		for($i=0;$i<count($bool_values);$i++) {
			if(false && !empty($options[$bool_values[$i]]))
				set_config($bool_values[$i],trim($options[$bool_values[$i]]),'emailed_files');
			unset($options[$bool_values[$i]]);
		}
        $ret = parent::set_option($options);
        return $ret;
    }
    /**
     * Add Plugin settings input to Moodle form
     *
     * @param moodleform $mform Moodle form (passed by reference)
     * @param string $classname repository class name
     */
    public static function type_config_form($mform, $classname = 'repository') {
        global $CFG;
        parent::type_config_form($mform);
		$lang = 'repository_emailed_files';

        $strrequired = get_string('required');

		$values = array('host','port','protocol','box','user','pass','use_ssl','validate_cert','purge_age_days');
		$bools = array('use_ssl','validate_cert');
		$nums = array('port','purge_age_days');
		$sels = array('protocol');

		for($i=0;$i<count($values);$i++) {
			$id = $values[$i];
			$v[$id] = get_config('emailed_files',$values[$i]);
			$type[$id] = 'text';
			if(@in_array($values[$i],$bools)) {
				$type[$id] = 'advcheckbox';
				$v[$id] = ($v[$id])?'1':'0';
			}
			if(@in_array($values[$i],$nums)) $type[$id] = 'text';
			if(@in_array($values[$i],$sels)) $type[$id] = 'select';
			$info[$id] = get_string($id,'repository_emailed_files');
		}
        $mform->addElement($type['host'], 'host', $info['host'], array('value'=>$v['host'],'size' => '20'));
        $mform->addElement($type['port'], 'port', $info['port'], array('value'=>$v['port'],'size' => '4'));

		// Enable this if you want to support different protocols. POP3 would be possible, but is not implemented yet
        //$s = $mform->addElement($type['protocol'], 'protocol', $info['protocol'], array('imap'=>'imap','pop3'=>'pop3'));
        //$s->setSelected($v['protocol']);
        $mform->addElement($type['box'], 'box', $info['box'], array('value'=>$v['box'],'size' => '10'));
        $mform->addElement($type['user'], 'user', $info['user'], array('value'=>$v['user'],'size' => '10'));
        $mform->addElement($type['pass'], 'pass', $info['pass'], array('value'=>$v['pass'],'size'=>10));
        $mform->addElement($type['use_ssl'], 'use_ssl', $info['use_ssl'],$v['use_ssl']);
        $mform->addElement($type['validate_cert'], 'validate_cert', $info['validate_cert'], $v['validate_cert']);
        $mform->addElement($type['purge_age_days'], 'purge_age_days', $info['purge_age_days'], array('value'=>$v['purge_age_days'],'size'=>'2'));

		for($i=0;$i<count($values);$i++)
			$mform->setType($values[$i], PARAM_TEXT);
       	$mform->setType('port', PARAM_INT);
       	$mform->setType('purge_age_days', PARAM_INT);
       	$mform->setType('use_ssl', PARAM_BOOL);
       	$mform->setType('validate_cert', PARAM_BOOL);
		$mform->setDefault('purge_age_days',0);
    }

    /**
     * Option names of emailed_files plugin
     *
     * @return array
     */
    public static function get_type_option_names() {
        return array('pluginname','host','protocol','port','path','box','user','pass','use_ssl','validate_cert','purge_age_days');
    }


    /**
     * Emailed_files plugin supports all kinds of files
     *
     * @return array
     */
    public function supported_filetypes() {
        return '*';
    }

    /**
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL; // | FILE_REFERENCE | FILE_EXTERNAL;
    }
}
