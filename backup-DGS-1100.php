<?php
define('DEFAULT_BACKUP_DIR', '/var/backups');
define('DEFAULT_CONFIG_FILE', '/etc/backup-DSG-1100.xml');
define('DEFAULT_LOG_FACILITY', LOG_USER);
define('DEFAULT_DEBUG', FALSE);
define('DEFAULT_OUTPUT_FILENAMES', false);
define('DEFAULT_SAVE_PASSWORD_IN_CONFIG_FILE', TRUE);
define('DEFAULT_BACKUP_FIRMWARE', FALSE);

/*
 * Supporting functions
 */

/**
 * Return value of a configuration parameter from config file
 *
 * @param DOMDocument $domObj
 * @param string $setting
 * @return mixed|NULL return value of xml setting. NULL in error
 */
function getConfigSetting($domObj, $setting)
{
  $nodeList = $domObj->getElementsByTagName($setting);
  if ((! is_object($nodeList)) || (get_class($nodeList) !== 'DOMNodeList')) {
    return NULL;
  }
  switch ($nodeList->length) {
    case 0:
      syslog(LOG_DEBUG, "Config parameter $setting was not found");
      return NULL;
      break;
    case 1:
      $value = $nodeList->getIndex(0)->nodeValue;
      syslog(LOG_DEBUG, "Returning $value for settting $setting");
      return $value;
      break;
    default:
      $msg = "Could not determine paramter $setting value. Possible syntax error in config file";
      syslog(LOG_ERR, $msg);
      fwrite(STDERR, PHP_EOL . $msg);
      return NULL;
  }
}

/**
 * Setup base resources
 */
// openlog(pathinfo(__FILE__,PATHINFO_BASENAME), LOG_CONS, $facility);

/*
 * Getting options from CLI
 */
$shortOpts = 'c:o';
$longOpts = array(
  'config:',
  'output'
);
$cliOptions = getopt($shortOpts, $longOpts);
if (! is_array($cliOptions)) {
  $msg = 'Could not parse CLI options';
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, PHP_EOL . $msg);
  exit(2);
}
var_dump($cliOptions);
foreach ($cliOptions as $optKey => $optValue) {
  switch ($optKey) {
    case 'c':
    case 'config':
      $cliConfigFile = $optValue;
      break;
    case 'o':
    case 'output':
      $cliOutputEnabled = true;
      break;
  }
}

/**
 * Loading configuration
 */

$configFile = isset($cliConfigFile) ? $cliConfigFile : DEFAULT_CONFIG_FILE;

if (! is_readable($configFile)) {
  $msg = "$configFile does not exitst or is unreadable";
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, PHP_EOL . $msg);
  exit(2);
}

$domDocumentObj = new DOMDocument();
$cfgXMLStr = file_get_contents($configFile);
if (! is_string($cfgXMLStr)) {
  $msg = "Failed to read $configFile file";
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, $msg);
  exit(2);
}

if (! $domDocumentObj->loadXML($cfgXMLStr)) {
  $msg = "Failed to import XML to DOMDocument";
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, $msg);
  exit(2);
}

// Backup directory
$backupDir = (! is_null(getConfigSetting($domDocumentObj, 'backupDirectory'))) ? (string) getConfigSetting($domDocumentObj, 'backupDirectory') : DEFAULT_BACKUP_DIR;

// Syslog Facility
$logFacility = (! is_null(getConfigSetting($domDocumentObj, 'logFacility'))) ? (string) getConfigSetting($domDocumentObj, 'logFacility') : DEFAULT_LOG_FACILITY;

// Save Password in the backup
$savePasswordsInConfigurationBackup = (! is_null(getConfigSetting($domDocumentObj, 'savePasswordsInConfigurationBackup'))) ? (bool) getConfigSetting($domDocumentObj, 'savePasswordsInConfigurationBackup') : DEFAULT_SAVE_PASSWORD_IN_CONFIG_FILE;

// Firmware
$backupFirmware = (! is_null(getConfigSetting($domDocumentObj, 'backupFirmware'))) ? (bool) getConfigSetting($domDocumentObj, 'backupFirmware') : DEFAULT_BACKUP_FIRMWARE;

// IP address
$IPv4 = getConfigSetting($domDocumentObj, 'IPv4');
if (is_null($IPv4)) {
  $msg = "Failed to get IPv4 parameter";
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, PHP_EOL . $msg);
  exit(2);
}
if (filter_var($IPv4, FILTER_VALIDATE_IP) === FALSE) {
  $msg = "IPv4 parater is not valid IPv4 address";
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, PHP_EOL . $msg);
  exit(2);
}

// Switch password
$switchPassword = getConfigSetting($domDocumentObj, 'password');
if (is_null($switchPassword)) {
  $msg = "Failed to get password parameter";
  syslog(LOG_ERR, $msg);
  fwrite(STDERR, PHP_EOL . $msg);
  exit(2);
}
$switchPasswordMD5 = md5($switchPassword);

$cookieFile = tempnam(sys_get_temp_dir(), '');

$curlOptsCommon = array(
  CURLOPT_COOKIESESSION => TRUE,
  CURLOPT_COOKIEFILE => $cookieFile,
  CURLOPT_COOKIEJAR => $cookieFile
);

$curlRes = curl_init();
$curlOpts = array(
  CURLOPT_URL => "http://$IPv4/cgi/login.cgi",
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query(array(
    'pass' => $switchPasswordMD5
  ))
);
curl_setopt_array($curlRes, ($curlOptsCommon + $curlOpts));
curl_exec($curlRes);

