<?php

/*
 * This file is part of the Fetch package.
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fetch;

/**
 * This library is a wrapper around the Imap library functions included in php. This class represents a single email
 * message as retrieved from the Imap.
 *
 * @package Fetch
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Message
{
    /**
     * Primary Body Types
     * According to http://www.php.net/manual/en/function.imap-fetchstructure.php
     */
    const TYPE_TEXT = 0;
    const TYPE_MULTIPART = 1;
    const TYPE_MESSAGE = 2;
    const TYPE_APPLICATION = 3;
    const TYPE_AUDIO = 4;
    const TYPE_IMAGE = 5;
    const TYPE_VIDEO = 6;
    const TYPE_OTHER = 7;

    /**
     * This is the connection/mailbox class that the email came from.
     *
     * @var Server
     */
    protected $imapConnection;

    /**
     * This is the unique identifier for the message. This corresponds to the imap "uid", which we use instead of the
     * sequence number.
     *
     * @var int
     */
    protected $uid;

    /**
     * This is a reference to the Imap stream generated by 'imap_open'.
     *
     * @var resource
     */
    protected $imapStream;

    /**
     * This as an object which contains header information for the message.
     *
     * @var \stdClass
     */
    protected $headers;

    /**
     * This is an object which contains various status messages and other information about the message.
     *
     * @var \stdClass
     */
    protected $messageOverview;

    /**
     * This is an object which contains information about the structure of the message body.
     *
     * @var \stdClass
     */
    protected $structure;

    /**
     * This is an array with the index being imap flags and the value being a boolean specifying whether that flag is
     * set or not.
     *
     * @var array
     */
    protected $status = array();

    /**
     * This is an array of the various imap flags that can be set.
     *
     * @var string
     */
    protected static $flagTypes = array('recent', 'flagged', 'answered', 'deleted', 'seen', 'draft');

    /**
     * This holds the plantext email message.
     *
     * @var string
     */
    protected $plaintextMessage;

    /**
     * This holds the html version of the email.
     *
     * @var string
     */
    protected $htmlMessage;

    /**
     * This is the date the email was sent.
     *
     * @var int
     */
    protected $date;

    /**
     * This is the subject of the email.
     *
     * @var string
     */
    protected $subject;

    /**
     * This is the size of the email.
     *
     * @var int
     */
    protected $size;

    /**
     * This is an array containing information about the address the email came from.
     *
     * @var string
     */
    protected $from;

    /**
     * This is an array of arrays that contains information about the addresses the email was sent to.
     *
     * @var array
     */
    protected $to;

    /**
     * This is an array of arrays that contains information about the addresses the email was cc'd to.
     *
     * @var array
     */
    protected $cc;

    /**
     * This is an array of arrays that contains information about the addresses the email was bcc'd to.
     *
     * @var array
     */
    protected $bcc;

    /**
     * This is an array of arrays that contain information about the addresses that should receive replies to the email.
     *
     * @var array
     */
    protected $replyTo;

    /**
     * This is an array of ImapAttachments retrieved from the message.
     *
     * @var Attachment[]
     */
    protected $attachments = array();

    /**
     * Contains the mailbox that the message resides in.
     *
     * @var string
     */
    protected $mailbox;

    /**
     * This value defines the encoding we want the email message to use.
     *
     * @var string
     */
    public static $charset = 'UTF-8//TRANSLIT';

    /**
     * This constructor takes in the uid for the message and the Imap class representing the mailbox the
     * message should be opened from. This constructor should generally not be called directly, but rather retrieved
     * through the apprioriate Imap functions.
     *
     * @param int    $messageUniqueId
     * @param Server $mailbox
     */
    public function __construct($messageUniqueId, Server $connection)
    {
        $this->imapConnection = $connection;
        $this->mailbox        = $connection->getMailBox();
        $this->uid            = $messageUniqueId;
        $this->imapStream     = $this->imapConnection->getImapStream();
        if($this->loadMessage() !== true)
            throw new \RuntimeException('Message with ID ' . $messageUniqueId . ' not found.');
    }

    /**
     * This function is called when the message class is loaded. It loads general information about the message from the
     * imap server.
     *
     */
    protected function loadMessage()
    {
        /* First load the message overview information */

        if(!is_object($messageOverview = $this->getOverview()))

            return false;

        $this->subject = $messageOverview->subject;
        $this->date    = strtotime($messageOverview->date);
        $this->size    = $messageOverview->size;

        foreach (self::$flagTypes as $flag)
            $this->status[$flag] = ($messageOverview->$flag == 1);

        /* Next load in all of the header information */

        $headers = $this->getHeaders();

        if (isset($headers->to))
            $this->to = $this->processAddressObject($headers->to);

        if (isset($headers->cc))
            $this->cc = $this->processAddressObject($headers->cc);

        if (isset($headers->bcc))
            $this->bcc = $this->processAddressObject($headers->bcc);

        $this->from    = $this->processAddressObject($headers->from);
        $this->replyTo = isset($headers->reply_to) ? $this->processAddressObject($headers->reply_to) : $this->from;

        /* Finally load the structure itself */

        $structure = $this->getStructure();

        if (!isset($structure->parts)) {
            // not multipart
            $this->processStructure($structure);
        } else {
            // multipart
            foreach ($structure->parts as $id => $part) {
                if (!empty($part->description)) {
                    $cleanFilename = $this->makeFilenameSafe($part->description);
                    $part->description = $cleanFilename;
                    foreach ($part->parameters as $key => $parameter) {
                        if ($parameter->attribute === "name") {
                            $part->parameters[$key]->value = $cleanFilename;
                        }
                    }
                    foreach ($part->dparameters as $key => $dparameter) {
                        if ($dparameter->attribute === "filename") {
                            $part->dparameters[$key]->value = $cleanFilename;
                        }
                    }
                }

                $this->processStructure($part, $id + 1);
            }
        }

        return true;
    }

    /**
     * This function returns an object containing information about the message. This output is similar to that over the
     * imap_fetch_overview function, only instead of an array of message overviews only a single result is returned. The
     * results are only retrieved from the server once unless passed true as a parameter.
     *
     * @param  bool      $forceReload
     * @return \stdClass
     */
    public function getOverview($forceReload = false)
    {
        if ($forceReload || !isset($this->messageOverview)) {
            // returns an array, and since we just want one message we can grab the only result
            $results               = imap_fetch_overview($this->imapStream, $this->uid, FT_UID);
            $this->messageOverview = array_shift($results);
        }

        return $this->messageOverview;
    }

    /**
     * This function returns an object containing the headers of the message. This is done by taking the raw headers
     * and running them through the imap_rfc822_parse_headers function. The results are only retrieved from the server
     * once unless passed true as a parameter.
     *
     * @param  bool      $forceReload
     * @return \stdClass
     */
    public function getHeaders($forceReload = false)
    {
        if ($forceReload || !isset($this->headers)) {
            // raw headers (since imap_headerinfo doesn't use the unique id)
            $rawHeaders = imap_fetchheader($this->imapStream, $this->uid, FT_UID);

            // convert raw header string into a usable object
            $headerObject = imap_rfc822_parse_headers($rawHeaders);

            // to keep this object as close as possible to the original header object we add the udate property
            $headerObject->udate = strtotime($headerObject->date);

            $this->headers = $headerObject;
        }

        return $this->headers;
    }

    /**
     * This function returns an object containing the structure of the message body. This is the same object thats
     * returned by imap_fetchstructure. The results are only retrieved from the server once unless passed true as a
     * parameter.
     *
     * @param  bool      $forceReload
     * @return \stdClass
     */
    public function getStructure($forceReload = false)
    {
        if ($forceReload || !isset($this->structure)) {
            $this->structure = imap_fetchstructure($this->imapStream, $this->uid, FT_UID);
        }

        return $this->structure;
    }

    /**
     * This function returns the message body of the email. By default it returns the plaintext version. If a plaintext
     * version is requested but not present, the html version is stripped of tags and returned. If the opposite occurs,
     * the plaintext version is given some html formatting and returned. If neither are present the return value will be
     * false.
     *
     * @param  bool        $html Pass true to receive an html response.
     * @return string|bool Returns false if no body is present.
     */
    public function getMessageBody($html = false)
    {
        if ($html) {
            if (!isset($this->htmlMessage) && isset($this->plaintextMessage)) {
                $output = nl2br($this->plaintextMessage);

                return $output;

            } elseif (isset($this->htmlMessage)) {
                return $this->htmlMessage;
            }
        } else {
            if (!isset($this->plaintextMessage) && isset($this->htmlMessage)) {
                $output = preg_replace('/\<br(\s*)?\/?\>/i', PHP_EOL, trim($this->htmlMessage) );
                $output = strip_tags($output);

                return $output;
            } elseif (isset($this->plaintextMessage)) {
                return $this->plaintextMessage;
            }
        }

        return false;
    }

    /**
     * This function returns either an array of email addresses and names or, optionally, a string that can be used in
     * mail headers.
     *
     * @param  string            $type     Should be 'to', 'cc', 'bcc', 'from', or 'reply-to'.
     * @param  bool              $asString
     * @return array|string|bool
     */
    public function getAddresses($type, $asString = false)
    {
        $type = ( $type == 'reply-to' ) ? 'replyTo' : $type;
        $addressTypes = array('to', 'cc', 'bcc', 'from', 'replyTo');

        if (!in_array($type, $addressTypes) || !isset($this->$type) || count($this->$type) < 1)
            return false;


        if (!$asString) {
            if ($type == 'from')
                return $this->from[0];

            return $this->$type;
        } else {
            $outputString = '';
            foreach ($this->$type as $address) {
                if (isset($set))
                    $outputString .= ', ';
                if (!isset($set))
                    $set = true;

                $outputString .= isset($address['name']) ?
                    $address['name'] . ' <' . $address['address'] . '>'
                    : $address['address'];
            }

            return $outputString;
        }
    }

    /**
     * This function returns the date, as a timestamp, of when the email was sent.
     *
     * @return int
     */
    public function getDate()
    {
        return isset($this->date) ? $this->date : false;
    }

    /**
     * This returns the subject of the message.
     *
     * @return string
     */
    public function getSubject()
    {
        return isset($this->subject) ? $this->subject : null;
    }

    /**
     * This function marks a message for deletion. It is important to note that the message will not be deleted form the
     * mailbox until the Imap->expunge it run.
     *
     * @return bool
     */
    public function delete()
    {
        return imap_delete($this->imapStream, $this->uid, FT_UID);
    }

    /**
     * This function returns Imap this message came from.
     *
     * @return Server
     */
    public function getImapBox()
    {
        return $this->imapConnection;
    }

    /**
     * Adds an attachment
     * 
     * If a filename is not provided and the attachment is a message/rfc822 
     * email, parse the Subject line and use it as the filename. If the Subject 
     * line is blank or illegible, use a default filename (like Gmail and some 
     * desktop clients do)
     *
     * @param array     $parameters
     * @param \stdClass $structure
     * @param string    $partIdentifier
     * @return boolean Successful attachment of file
     */
    protected function addAttachment($parameters, $structure, $partIdentifier)
    {
        if (!(isset($parameters["name"]) || isset($parameters["filename"])) && $structure->type == self::TYPE_MESSAGE) {
            $body = isset($partIdentifier) ?
                imap_fetchbody($this->imapStream, $this->uid, $partIdentifier, FT_UID)
                : imap_body($this->imapStream, $this->uid, FT_UID);
            
            $headers = iconv_mime_decode_headers($body, 0, self::$charset);
            $filename = !empty($headers["Subject"]) ? $this->makeFilenameSafe($headers["Subject"]) : "email";
            
            $dpar = new \stdClass();
            $dpar->attribute = "filename";
            $dpar->value = str_replace(array("\r", "\n"), '', $filename) . ".eml";
            $structure->dparameters[] = $dpar;
        }

        try {
            $attachment          = new Attachment($this, $structure, $partIdentifier);
            $this->attachments[] = $attachment;

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * This function extracts the body of an email part, strips harmful 
     * Outlook-specific strings from it, processes any encoded one-liners, 
     * decodes it, converts it to the charset of the parent message, and 
     * returns the result.
     *
     * @param array     $parameters
     * @param \stdClass $structure
     * @param string    $partIdentifier
     * @return string
     */
    protected function processBody($structure, $partIdentifier)
    {
        $rawBody = isset($partIdentifier) ?
                imap_fetchbody($this->imapStream, $this->uid, $partIdentifier, FT_UID)
                : imap_body($this->imapStream, $this->uid, FT_UID);
        
        $bodyNoOutlook = $this->stripOutlookSpecificStrings($rawBody);
        
        $decodedBody = self::decode($bodyNoOutlook, $structure->encoding);
        
        $inCharset = $inCharset = mb_detect_encoding($decodedBody, array(
            "US-ASCII",
            "ISO-8859-1",
            "UTF-8",
            "UTF-7",
            "ASCII",
            "EUC-JP",
            "SJIS",
            "eucJP-win",
            "SJIS-win",
            "JIS",
            "ISO-2022-JP",
            "UTF-16",
            "UTF-32",
            "UCS2",
            "UCS4")
        );
        
        if ($inCharset && $inCharset !== self::$charset) {
            $decodedBody = iconv($inCharset, self::$charset, $decodedBody);
        }

        return $decodedBody;
    }
    
    /**
     * Removes "Thread-Index:" line from the message body which is placed there 
     * by Outlook and messes up the other processing steps.
     * 
     * @param string $messageBody
     * @return string
     */
    protected function stripOutlookSpecificStrings($bodyBefore)
    {
        $bodyAfter = preg_replace('/Thread-Index:.*$/m', "", $bodyBefore);
        
        return $bodyAfter;
    }
    
    /**
     * This function takes in a string to be used as a filename and replaces 
     * any dangerous characters with underscores to ensure compatibility with 
     * various file systems
     * 
     * @param string $oldName
     * @return string
     */
    protected function makeFilenameSafe($oldName)
    {
        return preg_replace('/[<>"{}|\\\^\[\]`;\/\?:@&=$,]/',"_", $oldName);
    }
    
    /**
     * This function takes in a structure and identifier and processes that part of the message. If that portion of the
     * message has its own subparts, those are recursively processed using this function.
     *
     * @param \stdClass $structure
     * @param string    $partIdentifier
     */
    protected function processStructure($structure, $partIdentifier = null)
    {
        $attached = false;
        
        // TODO: Get HTML attachments working, too!
        if ((isset($structure->disposition) && $structure->disposition == "attachment") &&
            !($structure->type == self::TYPE_TEXT || $structure->type == self::TYPE_MULTIPART)) {
            $parameters = self::getParametersFromStructure($structure);
            $attached = $this->addAttachment($parameters, $structure, $partIdentifier);
        }

        if (!$attached && ($structure->type == self::TYPE_TEXT || $structure->type == self::TYPE_MULTIPART)) {
            $messageBody = $this->processBody($structure, $partIdentifier);

            if (strtolower($structure->subtype) === 'plain' || ($structure->type == self::TYPE_MULTIPART && strtolower($structure->subtype) !== 'alternative')) {
                if (isset($this->plaintextMessage)) {
                    $this->plaintextMessage .= PHP_EOL . PHP_EOL;
                } else {
                    $this->plaintextMessage = '';
                }

                $this->plaintextMessage .= trim($messageBody);
            } else {
                if (isset($this->htmlMessage)) {
                    $this->htmlMessage .= '<br><br>';
                } else {
                    $this->htmlMessage = '';
                }

                $this->htmlMessage .= $messageBody;
            }

            if (isset($structure->parts)) { // multipart: iterate through each part
                foreach ($structure->parts as $partIndex => $part) {
                    $partId = $partIndex + 1;

                    if (isset($partIdentifier))
                        $partId = $partIdentifier . '.' . $partId;

                    $this->processStructure($part, $partId);
                }
            }
        }
    }

    /**
     * This function takes in the message data and encoding type and returns the decoded data.
     *
     * @param  string     $data
     * @param  int|string $encoding
     * @return string
     */
    public static function decode($data, $encoding)
    {
        if (!is_numeric($encoding))
            $encoding = strtolower($encoding);
        
        switch ($encoding) {
            case 'quoted-printable':
            case 4:
                return quoted_printable_decode($data);

            case 'base64':
            case 3:
                return base64_decode($data);

            default:
                return $data;
        }
    }
    
    /**
     * This function returns the body type that an imap integer maps to.
     *
     * @param  int    $id
     * @return string
     */
    public static function typeIdToString($id)
    {
        switch ($id) {
            case 0:
                return 'text';

            case 1:
                return 'multipart';

            case 2:
                return 'message';

            case 3:
                return 'application';

            case 4:
                return 'audio';

            case 5:
                return 'image';

            case 6:
                return 'video';

            default:
            case 7:
                return 'other';
        }
    }

    /**
     * Takes in a section structure and returns its parameters as an associative array.
     *
     * @param  \stdClass $structure
     * @return array
     */
    public static function getParametersFromStructure($structure)
    {
        $parameters = array();
        if (isset($structure->parameters)) {
            foreach ($structure->parameters as $parameter) {
                $parameters[strtolower($parameter->attribute)] = $parameter->value;
            }
        }

        if (isset($structure->dparameters)) {
            foreach ($structure->dparameters as $parameter) {
                $parameters[strtolower($parameter->attribute)] = $parameter->value;
            }
        }

        return $parameters;
    }

    /**
     * This function takes in an array of the address objects generated by the message headers and turns them into an
     * associative array.
     *
     * @param  array $addresses
     * @return array
     */
    protected function processAddressObject($addresses)
    {
        $outputAddresses = array();
        if (is_array($addresses))
            foreach ($addresses as $address) {
                $currentAddress            = array();
                $currentAddress['address'] = $address->mailbox . '@' . $address->host;
                if (isset($address->personal))
                    $currentAddress['name'] = $address->personal;
                $outputAddresses[] = $currentAddress;
            }

        return $outputAddresses;
    }

    /**
     * This function returns the unique id that identifies the message on the server.
     *
     * @return int
     */
    public function getUid()
    {
        return $this->uid;
    }

    /**
     * This function returns the attachments a message contains. If a filename is passed then just that ImapAttachment
     * is returned, unless
     *
     * @param  null|string             $filename
     * @return array|bool|Attachment[]
     */
    public function getAttachments($filename = null)
    {
        if (!isset($this->attachments) || count($this->attachments) < 1)
            return false;

        if (!isset($filename))
            return $this->attachments;

        $results = array();
        foreach ($this->attachments as $attachment) {
            if ($attachment->getFileName() == $filename)
                $results[] = $attachment;
        }

        switch (count($results)) {
            case 0:
                return false;

            case 1:
                return array_shift($results);

            default:
                return $results;
                break;
        }
    }

    /**
     * This function checks to see if an imap flag is set on the email message.
     *
     * @param  string $flag Recent, Flagged, Answered, Deleted, Seen, Draft
     * @return bool
     */
    public function checkFlag($flag = 'flagged')
    {
        return (isset($this->status[$flag]) && $this->status[$flag] === true);
    }

    /**
     * This function is used to enable or disable a flag on the imap message.
     *
     * @param  string                    $flag   Flagged, Answered, Deleted, Seen, Draft
     * @param  bool                      $enable
     * @throws \InvalidArgumentException
     * @return bool
     */
    public function setFlag($flag, $enable = true)
    {
        if (!in_array($flag, self::$flagTypes) || $flag == 'recent')
            throw new \InvalidArgumentException('Unable to set invalid flag "' . $flag . '"');

        $imapifiedFlag = '\\' . ucfirst($flag);

        if ($enable === true) {
            $this->status[$flag] = true;

            return imap_setflag_full($this->imapStream, $this->uid, $imapifiedFlag, ST_UID);
        } else {
            unset($this->status[$flag]);

            return imap_clearflag_full($this->imapStream, $this->uid, $imapifiedFlag, ST_UID);
        }
    }

    /**
     * This function is used to move a mail to the given mailbox.
     *
     * @param $mailbox
     *
     * @return bool
     */
    public function moveToMailBox($mailbox)
    {
        $currentBox = $this->imapConnection->getMailBox();
        $this->imapConnection->setMailBox($this->mailbox);

        $returnValue = imap_mail_copy($this->imapStream, $this->uid, $mailbox, CP_UID | CP_MOVE);
        imap_expunge($this->imapStream);

        $this->mailbox = $mailbox;

        $this->imapConnection->setMailBox($currentBox);

        return $returnValue;
    }
}
