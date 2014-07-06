<?php
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
 * Repository to access Emailed files via IMAP or POP3
 *
 * @package    repository_emailed_files
 * @copyright  2014 Robert Schrenk (http://www.schrenk.cc)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        $options['page']    = optional_param('p', 1, PARAM_INT);
        parent::__construct($repositoryid, $context, $options);

	$this->host = get_config('emailed_files','host');
	$this->port = get_config('emailed_files','port');
	$this->type = get_config('emailed_files','type');
	$this->box = get_config('emailed_files','box');
	$this->user = get_config('emailed_files','user');
	$this->pass = get_config('emailed_files','pass');
	$this->use_ssl = (get_config('emailed_files','use_ssl')==1)?true:false;
	$this->validate_cert = (get_config('emailed_files','validate_cert')==1)?true:false;
	$this->purge_age_days = get_config('emailed_files','purge_age_days');
	$this->search = "FROM \"".$GLOBALS["USER"]->email."\"";

	$this->path = $this->type;
	if($this->use_ssl) $this->path .= "/ssl";
	if($this->use_ssl && !$this->validate_cert) $this->path .= "/novalidate-cert";

	$this->imap = "{".$this->host.":".$this->port.$this->path."}".$this->box;

        $callbackurl = new moodle_url($CFG->wwwroot.'/repository/repository_callback.php', array(
            'callback'=>'yes',
            'repo_id'=>$repositoryid
            ));
    }

    /**
     * Get emailed files
     *
     * @param string $path
     * @param int $page
     * @return array
     */
    public function get_listing($path = '', $page = '1') {
        global $OUTPUT;
        if (empty($path) || $path=='/') {
            $path = '/';
        } else {
            $path = file_correct_filepath($path);
        }

	$mailbox = imap_open(imap_utf7_encode($this->imap),$this->user,$this->pass);
	// Search for Mails to be deleted
	if($this->purge_age_days>0) {
		$d = mktime(date("H"),date("i"),date("s"),date("n"),date("j")-$this->purge_age_days,date("Y"));
		$this->log("D: ".date("d-M-Y",$d));
		$emails = imap_search($mailbox,"BEFORE \"".date("d-M-Y",$d)."\"");
		if($emails) {
			foreach($emails as $email_number) {
				$this->log("Deleting Mail #".$email_number);
				imap_delete($mailbox,$email_number);
			}
			imap_expunge($mailbox);
		}
	}


	// Search for Mails from current Moodle-User (identified by mailaddress) containing attachments
	$emails = imap_search($mailbox,$this->search,SE_FREE,"UTF8");
	$files = array();

	if($emails) {
		$i = 0;
		rsort($emails);
		foreach($emails as $email_number) {
			$i++;
			$overview = imap_fetch_overview($mailbox,$email_number,0);
			$subject = $overview[0]->subject;
			$date = $overview[0]->date;

			$structure = imap_fetchstructure($mailbox, $email_number);
       			$attachments = $this->read_attachments($structure);
			if(count($attachments)>0) {
				for($i=0;$i<count($attachments);$i++) {
					if($attachments[$i]['is_attachment']) {
						$z = count($files);
						$files[$z]["email_number"] = $email_number;
						$files[$z]["structure_number"] = $i;
						$files[$z]["subject"] = $subject;
						$files[$z]["date"] = $date;
						$files[$z]["size"] = $attachments[$i]["bytes"];
						$files[$z]["filename"] = $attachments[$i]["filename"];
					}
				}
			}
		}
		@imap_close($mailbox);
	} else {
		@imap_close($mailbox);
		return false;
	}

        $dirslist = array();
        $fileslist = array();
        foreach ($files as $file) {
        	$thumbnail = null;
		$title_ = imap_mime_header_decode($file["filename"]);
		$title = "";
		foreach($title_ as $t)
			$title .= $t->text;
        	$fileslist[] = array(
        		'title' => $title,
        		'source' => $file["email_number"]."_".$file["structure_number"],
        		'size' => $file["size"],
        		'date' => strtotime($file["date"]),
        		'thumbnail' => null, // $OUTPUT->pix_url(file_extension_icon($file->path, 64))->out(false),
        		'realthumbnail' => $thumbnail,
        		'thumbnail_height' => 64,
        		'thumbnail_width' => 64,
			'author' => $GLOBALS["USER"]->email,
        	);
        }
        $fileslist = array_filter($fileslist, array($this, 'filter'));
        $list['list'] = array_merge($dirslist, array_values($fileslist));
        return $list;
    }

    /**
	Reads Attachments from email_structures
	@param $structure imap_fetchstructure result for specific email
     */
    public function read_attachments($structure) {
       $attachments = array();
        if(isset($structure->parts) && count($structure->parts)) {
            for($i = 0; $i < count($structure->parts); $i++) {
                $attachments[$i] = array(
                    'is_attachment' => false,
                    'filename' => '',
                    'name' => '',
                    'attachment' => '',
		    'bytes' => $structure->parts[$i]->bytes,
                );
 
                if($structure->parts[$i]->ifdparameters) {
                    foreach($structure->parts[$i]->dparameters as $object) {
                        if(strtolower($object->attribute) == 'filename') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['filename'] = $object->value;
                        }
                    }
                }
 
                if($structure->parts[$i]->ifparameters) {
                    foreach($structure->parts[$i]->parameters as $object) {
                        if(strtolower($object->attribute) == 'name') {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['filename'] = $object->value;
                        }
                    }
                }
            }
        }
	return $attachments;
    }

    /**
     * Downloads a file from external repository and saves it in temp dir
     *
     * @throws moodle_exception when file could not be downloaded
     *
     * @param string $reference containing emailnumber + _ + structurenumber
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

	$mailbox = @imap_open(imap_utf7_encode($this->imap),$this->user,$this->pass);

	if(!$mailbox) throw new moodle_exception('cannotdownload', 'repository');
	$structure = @imap_fetchstructure($mailbox, $email_number);
	
        $attachment = @imap_fetchbody($mailbox, $email_number, $structure_number+1);

        /* 3 = BASE64 encoding */
        if($structure->parts[$structure_number]->encoding == 3) { 
        	$attachment = base64_decode($attachment);
        }
        /* 4 = QUOTED-PRINTABLE encoding */
        if($structure->parts[$structure_number]->encoding == 4) { 
        	$attachment = quoted_printable_decode($attachment);
        }

	if(strlen($attachment)==0) throw new moodle_exception('cannotdownload', 'repository');

	$fp = fopen($saveas, "w+");
	fwrite($fp, $attachment);
	fclose($fp);
	imap_close($mailbox);
	return array('path'=>$saveas); //, 'url'=>'');
    }
    /**
     * Set email option
     * @param array $options
     * @return mixed
     */
    public function set_option($options = array()) {
	$str_values = array('host','type','box','user','pass');
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

	$values = array('host','port','type','box','user','pass','use_ssl','validate_cert','purge_age_days');
	$bools = array('use_ssl','validate_cert');
	$nums = array('port','purge_age_days');
	$sels = array('type');

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
        $s = $mform->addElement($type['type'], 'type', $info['type'], array('imap'=>'imap','pop3'=>'pop3'));
        $s->setSelected($v['type']);
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
        return array('pluginname','host','port','path','box','user','pass','use_ssl','validate_cert','purge_age_days');
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
