<?php

class WebfilezNotAuthorizedException
{
        /* pass */
}

// ------------------------------------------------------------------------

/**
 * Webfilez Main Execution Class
 */
class Webfilez {

    /**
     * @var FileManager
     */
    private $fileMgr;

    /**
     * @var Reqresp\Request
     */
    private $request;

    /**
     * @var Reqresp\Response
     */
    private $response;

    /**
     * @var Reqresp\Url
     */
    private $url;

    /**
     * @var UploadHandler
     */
    private $uploadHandler;

    /**
     * @var array
     */
    private $mimeTypes;

    /**
     * @var string
     */
    private $folderName;

    // ------------------------------------------------------------------------

    /**
     * Webfilez Main Exeuction Runner
     *
     * @param string $folderName
     * Optionally pass in the folder to use (required for session strategy - see config)
     */
    public static function main($folderName = null) {
        $that = new Webfilez($folderName);
        $that->run();
    }

    // ------------------------------------------------------------------------

    /**
     * Embed the Webfilez Interface in another site
     *
     * @param string $webfilezUrl
     * The URL for Webfilez
     *
     * @param string $folderName
     * Optionally pass in the folder to use (required for session strategy - see config)
     */
    public static function embed($webfilezUrl = null, $folderName = null) {
        $that = new Webfilez($folderName);
        $output = $that->loadInterface(false, $webfilezUrl);
        return $output;
    }

    // ------------------------------------------------------------------------

    /**
     * Constructor
     *
     * @param string $folderName
     * Optionally pass in the folder to use (required for session strategy - see config)
     */
    public function __construct($folderName = null) {

        //Basepath
        define('BASEPATH' , __DIR__ . DIRECTORY_SEPARATOR);

        //Autoloader
        spl_autoload_register(array($this, 'autoloader'), TRUE, TRUE);

        $this->folderName = $folderName;

        //Libraries
        $this->loadLibraries();
    }

    // ------------------------------------------------------------------------

    /**
     * JFile Main Execution Script
     */
    private function run()
    {
        //Error Manager
        Reqresp\ErrorWrapper::invoke();

        //Route It!
        try {
            $this->route();
            $this->response->go();
        }
        catch (WebfilezNotAuthorizedException $e) {
            $this->response->setStatus('401');

            if ($this->request->isAjax) {
                $this->response->setBody(json_encode(array('msg' => 'Not Authorized')));
            }
            else {
                $this->response->setBody("Not Authorized");
            }
        }
        catch (Exception $e) {
            //Catch 500 errors here
            throw $e;
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Load Libraries into Memory
     *
     * The order in which things get loaded in here matters
     */
    private function loadLibraries()
    {
        //First Tier
        $configdir      = BASEPATH . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
        $this->config   = new Configula\Config($configdir);
        $this->request  = new Reqresp\Request();
        $this->response = new Reqresp\Response();
        $this->url      = new Reqresp\Uri();

        //Second Tier
        $this->fileMgr       = new FileManager($this->getFolder(), array(), (boolean) $this->config->autobuild);
        $this->uploadHandler = new UploadHandler($this->fileMgr, $this->config->slow);
    }

    // ------------------------------------------------------------------------

    /**
     * Callback to get the folder
     *
     * Simple strategy function to return the foldername
     *
     * @return string
     * A path to the folder to use
     */
    private function getFolder()
    {
        switch ($this->config->foldername_strategy) {

            case 'callback':

                //Return the folderName if it was passed in
                if ($this->folderName) {
                    return $this->folderName;
                }

                if ($this->config->foldercallbackfile) {
                    include_once($this->config->foldercallbackfile);
                }

                if ( ! $this->config->foldercallback) {
                    throw new Exception("Folder Callback undefined!  Did you set it in the configuration?");
                }

                return call_user_func($this->config->foldercallback);
            break;

            case 'session': default:

                //Start a session if not already started
                if (session_id() == '') {
                    session_start();
                }

                //If webfilez was passed in
                if ($this->folderName) {
                    $_SESSION['webfilez_folder'] = $this->folderName;
                }

                if ( ! isset($_SESSION['webfilez_folder'])) {
                    throw new Exception("Folder Callback undefined!  Did you not pass it into the Webfilez app?");
                }

                return $_SESSION['webfilez_folder'];

            break;

        }
    }

    // ------------------------------------------------------------------------

    /**
     * Route the request and build the response using the Requesty Libraries
     */
    private function route() {

        //Getting upload status? Path: uploadstatus?id=##
        if ($this->url->path(1) == 'uploadstatus' && $this->url->query('id') !== false) {

            //Attempt to set the headers to disallow caching for this type of request
            $this->response->setHeader("Cache-Control: no-cache, must-revalidate");
            $this->response->setHeader("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

            //Get the data
            $respData = json_encode($this->uploadHandler->getUploadStatus($this->url->query('id')));

            //Set the output
            $this->response->setBody($respData);
        }

        //Getting server configuration?  Path: serverconfig?item=all or ?item=someitem
        elseif ($this->url->path(1) == 'serverconfig' && $this->url->query('item') !== false) {
            $this->routeServerConfig($this->url->query('item'));
        }

        //Default action will be to assume we are getting a resource (any other path)
        else {
            $respData = $this->routeFile();
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Main router for file-based actions
     */
    private function routeFile() {

        $path     = $this->url->path;
        $realpath = $this->fileMgr->resolveRealPath($path);
        $exists   = is_readable($realpath);
        $isDir    = ($exists & is_dir($realpath) OR $this->url->query('isdir') == true);

        switch($this->request->method) {

            case 'PUT':

                if ($this->request->header('IsDir') ?: $this->url->query('isDir')) {

                    try {
                        $result = $this->fileMgr->putDir($path);
                    }
                    catch (FileManagerIOException $e) {
                        $result = false;
                    }

                    if ($result) {
                        $this->response->setStatus(201);
                        $this->response->setBody(json_encode(array('created' => $path)));
                    }
                    else {
                        $this->response->setStatus(500);
                        $this->response->setBody(json_encode(array('msg' => $e->getMessage())));
                    }

                }
                else { //is file...

                    //Determine upload ID - Try header first, then query array
                    $fileUploadID = $this->request->header('Uploadfileid') ?: $this->url->query('id');

                    if ( ! $exists OR ($this->request->header('Overwrite') ?: $this->url->query('overwrite'))) {
                        $output = $this->uploadHandler->processUpload($path, $_SERVER['CONTENT_LENGTH'], $fileUploadID);
                        $this->response->setBody(json_encode($output));
                    }
                    else {
                        $this->response->setStatus(409);
                        $this->response->setBody(json_encode(array('msg' => 'File already exists')));
                    }
                }

            break;
            case 'POST':

                if ($exists) {
                    //Get the new name from the input.
                    //If no match, copy the file using the filePut and then delete the old one
                }
                else {
                    $this->response->setStatus(404);
                    $this->response->setBody(json_encode(array('msg' => 'File not found')));
                }

            break;
            case 'DELETE':

                if ($exists && ! $isDir) {
                    $fileInfo = $this->fileMgr->getFile($path);
                    $result = $this->fileMgr->deleteFile($path);
                    $this->response->setStatus(($result) ? '200' : '500');
                    $fileInfo['deleted'] = (int) $result;
                    $this->response->setBody(json_encode($fileInfo));
                }
                else {
                    $this->response->setStatus(404);
                    $this->response->setBody(json_encode(array('msg' => 'File not found')));
                }

            break;
            case 'GET': //GET will be the only method that supports HTML output
            default:

                if ( ! $isDir && $exists && $this->url->query('contents')) {
                    $ctype = $this->resolveMime(pathinfo($realpath, PATHINFO_EXTENSION)) ?: 'application/octet-stream';

                    $this->response->setHeader('Content-type: '. $ctype);
                    $this->response->setBody($realpath, Reqresp\Response::FILEPATH);
                }
                elseif ( ! $this->request->isAjax) {
                    $this->response->setBody($this->loadInterface());
                }
                else {
                    $this->routeGetFile($path, $realpath, $exists, $isDir);
                }

            break;
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Route GET requests for Files and Directories
     */
    private function routeGetFile($path, $realpath, $exists, $isDir) {

            //Stream the file
            if ($exists) {

                //Get the object
                $theObj = ($isDir)
                    ? $this->fileMgr->getDir($path)
                    : $this->fileMgr->getFile($path);

                //Output it
                $this->response->setBody(json_encode($theObj));
            }
            else { //Not exists
                $this->response->setHeader(404);
                $this->response->setBody(json_encode(array('msg' => 'File or folder not found')));
            }
    }

    // ------------------------------------------------------------------------

    /**
     * Get Server configuration
     *
     * @param string $item
     */
    private function routeServerConfig($item) {

        $config = array('a' => 1, 'b' => 2);
        if ($item == 'all') {
            $this->response->setBody(json_encode($config));
        }
        elseif (isset($config[$item])) {
            $this->response->setBody(json_encode(array($item => $config[$item])));
        }
        else {
            $this->response->setHeader(404);
            $this->response->setBody(json_encode(array('msg' => 'Configuration setting not found')));
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Resolve mime type for file download
     *
     * @param string $fileExtension
     * File extension
     *
     * @return string|boolean
     * Returns false if mime type not found
     *
     */
    private function resolveMime($fileExt) {

        //Load mimes if not already loaded
        if ( ! $this->mimeTypes) {
            require_once(BASEPATH . 'mimes.php');
            $configMimes = ((array) $this->config->mimeTypes) ?: array();
            $this->mimeTypes = array_merge($mimes, $configMimes);
        }

        //Try to resolve
        $fileExt = ltrim($fileExt, '.');

        if (isset($this->mimeTypes[$fileExt])) {
            return (is_array($this->mimeTypes[$fileExt])) ? $this->mimeTypes[$fileExt][0] : $this->mimeTypes[$fileExt];
        }
        else {
            return false;
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Load the interface HTML to download to a browser
     *
     * @param boolean $wrapper
     * Include the wrapper HTML code?
     *
     * @param string $baseurl
     * BaseURL (blank for current URL; only use this for embedding)
     *
     * @return string
     */
    private function loadInterface($wrapper = true, $baseurl = null)
    {
        //Variables
        $templateVars = array();
        $templateVars['siteurl']     = rtrim(($baseurl ?: $this->url->appurl), '/');
        $templateVars['baseurl']     = rtrim(($baseurl ?: $this->url->baseurl), '/');
        $templateVars['currentpath'] = ($baseurl) ? '' : $this->url->path;
        $templateVars['currenttype'] = is_dir($this->fileMgr->resolveRealPath($templateVars['currentpath'])) ? 'dir' : 'file';

        $ds = DIRECTORY_SEPARATOR;

        //Setup a clean function
        $clean = function($str, $templateVars) {

            //Remove anything between <? tags
            $str = preg_replace("/<\?(.+?)\?>(\n+)?/s", '', $str);

            //Template vars
            foreach ($templateVars as $search => $repl) {
                $str = str_replace('{' . $search . '}', $repl, $str);
            }
            return $str;
        };

        //Do the template output
        $html = $clean(
            file_get_contents(BASEPATH . "..{$ds}assets{$ds}html{$ds}template.html"),
            $templateVars
        );

        //If wrapper, do the wrapper output
        if ($wrapper) {
            $templateVars['webfilez'] = $html;

            $html = $clean(
                file_get_contents(BASEPATH . "..{$ds}assets{$ds}html{$ds}wrapper.html"),
                $templateVars
            );
        }
        return $html;
    }

    // ------------------------------------------------------------------------

    /**
     * PSR-0 Compliant Autoloader with hacks for non-PSR Compliant libraries
     *
     * @param string $classname
     */
    private function autoloader($classname)
    {
        $basepath = BASEPATH;
        $classname = ltrim($classname, '\\');
        $filename = '';

        if ($lnp = strripos($classname, '\\')) {
            $namespace = substr($classname, 0, $lnp);
            $classname = substr($classname, $lnp + 1);
            $filename = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
        }

        $filename .= str_replace('_', DIRECTORY_SEPARATOR, $classname) . '.php';
        $fullfilename = $basepath . $filename;

        //PSR-0 Compliant - Good to go!
        if (is_readable($fullfilename)) {
            require_once($fullfilename);
            return;
        }

        //Not PSR-0 Compliant - Try the slow way
        foreach (array_diff(scandir(BASEPATH), array('.', '..')) as $fn) {
            $fullfilename = BASEPATH . $fn . DIRECTORY_SEPARATOR . $filename;
            if (is_readable($fullfilename)) {
                require_once($fullfilename);
                return;
            }
        }
    }

    // ------------------------------------------------------------------------

}


/* EOF: Webfilez.php */