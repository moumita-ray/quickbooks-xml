<?php

/**
 * Example QuickBooks SOAP Server / Web Service
 * 
 * This is an example Web Service which imports Invoices currently stored 
 * within QuickBooks desktop editions and then stores those invoices in a MySQL 
 * database. It communicates with QuickBooks via the QuickBooks Web Connector.  
 * 
 * If you have not already looked at the more basic docs/example_server.php, 
 * you may want to consider looking at that example before you dive into this 
 * example, as the requests and processing are a bit simpler and the 
 * documentation a bit more verbose.
 * 
 * 
 * @author Keith Palmer <keith@consolibyte.com>
 * 
 * @package QuickBooks
 * @subpackage Documentation
 */

// I always program in E_STRICT error mode... 
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// Support URL
if (!empty($_GET['support']))
{
	header('Location: http://www.consolibyte.com/');
	exit;
}

// We need to make sure the correct timezone is set, or some PHP installations will complain
if (function_exists('date_default_timezone_set'))
{
	// * MAKE SURE YOU SET THIS TO THE CORRECT TIMEZONE! *
	// List of valid timezones is here: http://us3.php.net/manual/en/timezones.php
	date_default_timezone_set('America/New_York');
}

// Require the framework
require_once 'QuickBooks.php';

// A username and password you'll use in: 
//	a) Your .QWC file
//	b) The Web Connector
//	c) The QuickBooks framework
//
// 	NOTE: This has *no relationship* with QuickBooks usernames, Windows usernames, etc. 
// 		It is *only* used for the Web Connector and SOAP server! 
// 
// If you wanted to allow others to log in, you'd create a .QWC file for each 
//	individual user, and add each individual user to the auth database with the 
//	QuickBooks_Utilities::createUser($dsn, $username, $password); static method.
$user = 'quickbooks';
$pass = 'password';

/**
 * Configuration parameter for the quickbooks_config table, used to keep track of the last time the QuickBooks sync ran
 */
define('QB_QUICKBOOKS_CONFIG_LAST', 'last');

/**
 * Configuration parameter for the quickbooks_config table, used to keep track of the timestamp for the current iterator
 */
define('QB_QUICKBOOKS_CONFIG_CURR', 'curr');

/**
 * Maximum number of customers/invoices returned at a time when doing the import
 */
define('QB_QUICKBOOKS_MAX_RETURNED', 10);

/**
 * 
 */
define('QB_PRIORITY_PURCHASEORDER', 4);

/**
 * Request priorities, items sync first
 */
define('QB_PRIORITY_ITEM', 3);

/**
 * Request priorities, customers
 */
define('QB_PRIORITY_CUSTOMER', 2);

/**
 * Request priorities, salesorders
 */
define('QB_PRIORITY_SALESORDER', 1);

/**
 * Request priorities, invoices last... 
 */
define('QB_PRIORITY_INVOICE', 0);

/**
 * Send error notices to this e-mail address
 */
define('QB_QUICKBOOKS_MAILTO', 'keith@consolibyte.com');

// The next three parameters, $map, $errmap, and $hooks, are callbacks which 
//	will be called when certain actions/events/requests/responses occur within 
//	the framework.

// Map QuickBooks actions to handler functions
$map = array(
	//QUICKBOOKS_IMPORT_SALESRECEIPT => array( '_quickbooks_salesreceipt_import_request', '_quickbooks_salesreceipt_import_response' ), 
	//QUICKBOOKS_IMPORT_PURCHASEORDER => array( '_quickbooks_purchaseorder_import_request', '_quickbooks_purchaseorder_import_response' ),
	//QUICKBOOKS_IMPORT_INVOICE => array( '_quickbooks_invoice_import_request', '_quickbooks_invoice_import_response' ),
	QUICKBOOKS_IMPORT_CUSTOMER => array( '_quickbooks_customer_import_request', '_quickbooks_customer_import_response' ), 
	//QUICKBOOKS_IMPORT_SALESORDER => array( '_quickbooks_salesorder_import_request', '_quickbooks_salesorder_import_response' ), 
	//QUICKBOOKS_IMPORT_ITEM => array( '_quickbooks_item_import_request', '_quickbooks_item_import_response' ), 
	);

// Error handlers
$errmap = array(
	500 => '_quickbooks_error_e500_notfound', 			// Catch errors caused by searching for things not present in QuickBooks
	1 => '_quickbooks_error_e500_notfound', 
	'*' => '_quickbooks_error_catchall', 				// Catch any other errors that might occur
	);

// An array of callback hooks
$hooks = array(
	QuickBooks_WebConnector_Handlers::HOOK_LOGINSUCCESS => '_quickbooks_hook_loginsuccess', 	// call this whenever a successful login occurs
	);

// Logging level
//$log_level = QUICKBOOKS_LOG_NORMAL;
//$log_level = QUICKBOOKS_LOG_VERBOSE;
//$log_level = QUICKBOOKS_LOG_DEBUG;				// Use this level until you're sure everything works!!!
$log_level = QUICKBOOKS_LOG_DEVELOP;

// What SOAP server you're using 
//$soapserver = QUICKBOOKS_SOAPSERVER_PHP;			// The PHP SOAP extension, see: www.php.net/soap
$soapserver = QUICKBOOKS_SOAPSERVER_BUILTIN;		// A pure-PHP SOAP server (no PHP ext/soap extension required, also makes debugging easier)

$soap_options = array(			// See http://www.php.net/soap
	);

$handler_options = array(		// See the comments in the QuickBooks/Server/Handlers.php file
	'deny_concurrent_logins' => false, 
	'deny_reallyfast_logins' => false, 
	);		

$driver_options = array(		// See the comments in the QuickBooks/Driver/<YOUR DRIVER HERE>.php file ( i.e. 'Mysql.php', etc. )
	);

$callback_options = array(
	);

// * MAKE SURE YOU CHANGE THE DATABASE CONNECTION STRING BELOW TO A VALID MYSQL USERNAME/PASSWORD/HOSTNAME *
// 
// This assumes that:
//	- You are connecting to MySQL with the username 'root'
//	- You are connecting to MySQL with an empty password
//	- Your MySQL server is located on the same machine as the script ( i.e.: 'localhost', if it were on another machine, you might use 'other-machines-hostname.com', or '192.168.1.5', or ... etc. )
//	- Your MySQL database name containing the QuickBooks tables is named 'quickbooks' (if the tables don't exist, they'll be created for you) 
$dsn = 'mysqli://root:@localhost/quickbooks_sqli';
$dblink = mysqli_connect("localhost", "root", "", "quickbooks_sqli");
/**
 * Constant for the connection string (because we'll use it in other places in the script)
 */
define('QB_QUICKBOOKS_DSN', $dsn);

$file = dirname(__FILE__) . '\example.sql';
// If we haven't done our one-time initialization yet, do it now!
if (!QuickBooks_Utilities::initialized($dsn))
{
	// Create the example tables
	if (file_exists($file))
	{
		$contents = file_get_contents($file);	
		foreach (explode(';', $contents) as $sql)
		{
			if (!trim($sql))
			{
				continue;
			}
			
			mysqli_query($dblink, $sql) or die(trigger_error(mysqli_connect_error()));
		}
	}
	else
	{
		die('Could not locate "./example.sql" to create the demo SQL schema!');
	}
	
	// Create the database tables
	QuickBooks_Utilities::initialize($dsn);
	
	// Add the default authentication username/password
	QuickBooks_Utilities::createUser($dsn, $user, $pass);
}

// Initialize the queue
QuickBooks_WebConnector_Queue_Singleton::initialize($dsn);

// Create a new server and tell it to handle the requests
// __construct($dsn_or_conn, $map, $errmap = array(), $hooks = array(), $log_level = QUICKBOOKS_LOG_NORMAL, $soap = QUICKBOOKS_SOAPSERVER_PHP, $wsdl = QUICKBOOKS_WSDL, $soap_options = array(), $handler_options = array(), $driver_options = array(), $callback_options = array()
$Server = new QuickBooks_WebConnector_Server($dsn, $map, $errmap, $hooks, $log_level, $soapserver, QUICKBOOKS_WSDL, $soap_options, $handler_options, $driver_options, $callback_options);
$response = $Server->handle(true, true);

/*
// If you wanted, you could do something with $response here for debugging

$fp = fopen('/path/to/file.log', 'a+');
fwrite($fp, $response);
fclose($fp);
*/

/**
 * Login success hook - perform an action when a user logs in via the Web Connector
 *
 * 
 */
function _quickbooks_hook_loginsuccess($requestID, $user, $hook, &$err, $hook_data, $callback_config)
{
	// For new users, we need to set up a few things

	// Fetch the queue instance
	$Queue = QuickBooks_WebConnector_Queue_Singleton::getInstance();
	$date = '1983-01-02 12:01:01';
	
	// Set up the invoice imports
	if (!_quickbooks_get_last_run($user, QUICKBOOKS_IMPORT_INVOICE))
	{
		// And write the initial sync time
		_quickbooks_set_last_run($user, QUICKBOOKS_IMPORT_INVOICE, $date);
	}
	
	// Do the same for customers
	if (!_quickbooks_get_last_run($user, QUICKBOOKS_IMPORT_CUSTOMER))
	{
		_quickbooks_set_last_run($user, QUICKBOOKS_IMPORT_CUSTOMER, $date);
	}

	// ... and for sales orders
	if (!_quickbooks_get_last_run($user, QUICKBOOKS_IMPORT_SALESORDER))
	{
		_quickbooks_set_last_run($user, QUICKBOOKS_IMPORT_SALESORDER, $date);
	}
	
	// ... and for items
	if (!_quickbooks_get_last_run($user, QUICKBOOKS_IMPORT_ITEM))
	{
		_quickbooks_set_last_run($user, QUICKBOOKS_IMPORT_ITEM, $date);
	}
	
	// Make sure the requests get queued up
	//$Queue->enqueue(QUICKBOOKS_IMPORT_SALESORDER, 1, QB_PRIORITY_SALESORDER, null, $user);
	//$Queue->enqueue(QUICKBOOKS_IMPORT_INVOICE, 1, QB_PRIORITY_INVOICE, null, $user);
	//$Queue->enqueue(QUICKBOOKS_IMPORT_PURCHASEORDER, 1, QB_PRIORITY_PURCHASEORDER, null, $user);
	$Queue->enqueue(QUICKBOOKS_IMPORT_CUSTOMER, 1, QB_PRIORITY_CUSTOMER, null, $user);
	//$Queue->enqueue(QUICKBOOKS_IMPORT_ITEM, 1, QB_PRIORITY_ITEM, null, $user);
}

/**
 * Get the last date/time the QuickBooks sync ran
 * 
 * @param string $user		The web connector username 
 * @return string			A date/time in this format: "yyyy-mm-dd hh:ii:ss"
 */
function _quickbooks_get_last_run($user, $action)
{
	$type = null;
	$opts = null;
	return QuickBooks_Utilities::configRead(QB_QUICKBOOKS_DSN, $user, md5(__FILE__), QB_QUICKBOOKS_CONFIG_LAST . '-' . $action, $type, $opts);
}

/**
 * Set the last date/time the QuickBooks sync ran to NOW
 * 
 * @param string $user
 * @return boolean
 */
function _quickbooks_set_last_run($user, $action, $force = null)
{
	$value = date('Y-m-d') . 'T' . date('H:i:s');
	
	if ($force)
	{
		$value = date('Y-m-d', strtotime($force)) . 'T' . date('H:i:s', strtotime($force));
	}
	
	return QuickBooks_Utilities::configWrite(QB_QUICKBOOKS_DSN, $user, md5(__FILE__), QB_QUICKBOOKS_CONFIG_LAST . '-' . $action, $value);
}

/**
 * 
 * 
 */
function _quickbooks_get_current_run($user, $action)
{
	$type = null;
	$opts = null;
	return QuickBooks_Utilities::configRead(QB_QUICKBOOKS_DSN, $user, md5(__FILE__), QB_QUICKBOOKS_CONFIG_CURR . '-' . $action, $type, $opts);	
}

/**
 * 
 * 
 */
function _quickbooks_set_current_run($user, $action, $force = null)
{
	$value = date('Y-m-d') . 'T' . date('H:i:s');
	
	if ($force)
	{
		$value = date('Y-m-d', strtotime($force)) . 'T' . date('H:i:s', strtotime($force));
	}
	
	return QuickBooks_Utilities::configWrite(QB_QUICKBOOKS_DSN, $user, md5(__FILE__), QB_QUICKBOOKS_CONFIG_CURR . '-' . $action, $value);	
}


/**
 * Build a request to import customers already in QuickBooks into our application
 */
function _quickbooks_customer_import_request($requestID, $user, $action, $ID, $extra, &$err, $last_action_time, $last_actionident_time, $version, $locale)
{
	// Iterator support (break the result set into small chunks)
	$attr_iteratorID = '';
	$attr_iterator = ' iterator="Start" ';
	if (empty($extra['iteratorID']))
	{
		// This is the first request in a new batch
		$last = _quickbooks_get_last_run($user, $action);
		_quickbooks_set_last_run($user, $action);			// Update the last run time to NOW()
		
		// Set the current run to $last
		_quickbooks_set_current_run($user, $action, $last);
	}
	else
	{
		// This is a continuation of a batch
		$attr_iteratorID = ' iteratorID="' . $extra['iteratorID'] . '" ';
		$attr_iterator = ' iterator="Continue" ';
		
		$last = _quickbooks_get_current_run($user, $action);
	}
	
	// Build the request
	$xml = '<?xml version="1.0" encoding="utf-8"?>
		<?qbxml version="' . $version . '"?>
		<QBXML>
			<QBXMLMsgsRq onError="stopOnError">
				<CustomerQueryRq ' . $attr_iterator . ' ' . $attr_iteratorID . ' requestID="' . $requestID . '">
					<MaxReturned>' . QB_QUICKBOOKS_MAX_RETURNED . '</MaxReturned>
					<FromModifiedDate>' . $last . '</FromModifiedDate>
					<OwnerID>0</OwnerID>
				</CustomerQueryRq>	
			</QBXMLMsgsRq>
		</QBXML>';
		
	return $xml;
}

/** 
 * Handle a response from QuickBooks 
 */
function _quickbooks_customer_import_response($requestID, $user, $action, $ID, $extra, &$err, $last_action_time, $last_actionident_time, $xml, $idents)
{	
	if (!empty($idents['iteratorRemainingCount']))
	{
		// Queue up another request
		
		$Queue = QuickBooks_WebConnector_Queue_Singleton::getInstance();
		$Queue->enqueue(QUICKBOOKS_IMPORT_CUSTOMER, null, QB_PRIORITY_CUSTOMER, array( 'iteratorID' => $idents['iteratorID'] ), $user);
	}
	
	// This piece of the response from QuickBooks is now stored in $xml. You 
	//	can process the qbXML response in $xml in any way you like. Save it to 
	//	a file, stuff it in a database, parse it and stuff the records in a 
	//	database, etc. etc. etc. 
	//	
	// The following example shows how to use the built-in XML parser to parse 
	//	the response and stuff it into a database. 
	
	// Import all of the records
	$errnum = 0;
	$errmsg = '';
	$Parser = new QuickBooks_XML_Parser($xml);
	if ($Doc = $Parser->parse($errnum, $errmsg))
	{
		$Root = $Doc->getRoot();
		$List = $Root->getChildAt('QBXML/QBXMLMsgsRs/CustomerQueryRs');
		
		foreach ($List->children() as $Customer)
		{
			$arr = array(
				'ListID' => $Customer->getChildDataAt('CustomerRet ListID'),
				'TimeCreated' => $Customer->getChildDataAt('CustomerRet TimeCreated'),
				'TimeModified' => $Customer->getChildDataAt('CustomerRet TimeModified'),
				'Name' => $Customer->getChildDataAt('CustomerRet Name'),
				'FullName' => $Customer->getChildDataAt('CustomerRet FullName'),
				'FirstName' => $Customer->getChildDataAt('CustomerRet FirstName'),
				'MiddleName' => $Customer->getChildDataAt('CustomerRet MiddleName'),
				'LastName' => $Customer->getChildDataAt('CustomerRet LastName'),
				'Contact' => $Customer->getChildDataAt('CustomerRet Contact'),
				'ShipAddress_Addr1' => $Customer->getChildDataAt('CustomerRet ShipAddress Addr1'),
				'ShipAddress_Addr2' => $Customer->getChildDataAt('CustomerRet ShipAddress Addr2'),
				'ShipAddress_City' => $Customer->getChildDataAt('CustomerRet ShipAddress City'),
				'ShipAddress_State' => $Customer->getChildDataAt('CustomerRet ShipAddress State'),
				'ShipAddress_PostalCode' => $Customer->getChildDataAt('CustomerRet ShipAddress PostalCode'),
				);
			
			QuickBooks_Utilities::log(QB_QUICKBOOKS_DSN, 'Importing customer ' . $arr['FullName'] . ': ' . print_r($arr, true));
			
			foreach ($arr as $key => $value)
			{
				$arr[$key] = mysqli_real_escape_string($dblink, $value);
			}
			
			// Store the invoices in MySQL
			mysqli_query($dblink, "
				REPLACE INTO
					qb_example_customer
				(
					" . implode(", ", array_keys($arr)) . "
				) VALUES (
					'" . implode("', '", array_values($arr)) . "'
				)") or die(trigger_error(mysqli_connect_error()));
		}
	}
	
	return true;
}

/**
 * Handle a 500 not found error from QuickBooks
 * 
 * Instead of returning empty result sets for queries that don't find any 
 * records, QuickBooks returns an error message. This handles those error 
 * messages, and acts on them by adding the missing item to QuickBooks. 
 */
function _quickbooks_error_e500_notfound($requestID, $user, $action, $ID, $extra, &$err, $xml, $errnum, $errmsg)
{
	$Queue = QuickBooks_WebConnector_Queue_Singleton::getInstance();
	
	if ($action == QUICKBOOKS_IMPORT_INVOICE)
	{
		return true;
	}
	else if ($action == QUICKBOOKS_IMPORT_CUSTOMER)
	{
		return true;
	}
	else if ($action == QUICKBOOKS_IMPORT_SALESORDER)
	{
		return true;
	}
	else if ($action == QUICKBOOKS_IMPORT_ITEM)
	{
		return true;
	}
	else if ($action == QUICKBOOKS_IMPORT_PURCHASEORDER)
	{
		return true;
	}
	
	return false;
}


/**
 * Catch any errors that occur
 * 
 * @param string $requestID			
 * @param string $action
 * @param mixed $ID
 * @param mixed $extra
 * @param string $err
 * @param string $xml
 * @param mixed $errnum
 * @param string $errmsg
 * @return void
 */
function _quickbooks_error_catchall($requestID, $user, $action, $ID, $extra, &$err, $xml, $errnum, $errmsg)
{
	$message = '';
	$message .= 'Request ID: ' . $requestID . "\r\n";
	$message .= 'User: ' . $user . "\r\n";
	$message .= 'Action: ' . $action . "\r\n";
	$message .= 'ID: ' . $ID . "\r\n";
	$message .= 'Extra: ' . print_r($extra, true) . "\r\n";
	//$message .= 'Error: ' . $err . "\r\n";
	$message .= 'Error number: ' . $errnum . "\r\n";
	$message .= 'Error message: ' . $errmsg . "\r\n";
	
	mail(QB_QUICKBOOKS_MAILTO, 
		'QuickBooks error occured!', 
		$message);
}
