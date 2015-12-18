<?php

if (!isset($argv[1])) die(" Usage: lenc cron | domain [-f] [-r]\n  -f to enable SSL in ISPConfig DB\n  -r to update Apache Directives in ISPConfig DB to force http->https redirect\n
 Default: renew existing certificate and update corresponding record in ISPConfig DB\n");

if (!file_exists("/root/letsencrypt/letsencrypt-auto") OR !is_dir("/etc/letsencrypt")) 
        die("ERROR: Let's Encrypt not found in /root/letsencrypt or /etc/letsencrypt.\n");

if(!file_exists("/usr/local/ispconfig/server/lib/config.inc.php")) 
        die("ERROR: ISPConfig is missing.\n");
require_once "/usr/local/ispconfig/server/lib/config.inc.php";
require_once "/usr/local/ispconfig/server/lib/mysql_clientdb.conf";

#  /usr/local/ispconfig/server/lib/config.inc.php

$db = mysqli_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_database']) or die( "Can't connect to DB.\n");
#$db = mysqli_connect("localhost", "ispconfig", "d176144c6fce59dfd657b793f89f527b", "dbispconfig") or die( "Can't connect to DB.\n");
#$db = mysqli_connect("localhost", "c1isp", "77rNOJgb", "c1isp") or die( "Can't connect to DB.\n");

# $selected = mysqli_select_db($db, 'c1isp') or die("Strange shit happens: can't select DB.\n");
$selected = mysqli_select_db($db, 'dbispconfig') or die("Strange shit happens: can't select DB.\n");

if ($argv[1] !== "cron")
        echo letsencrypt($db,$argv[1],$argv);
  else {$sql = "SELECT web_domain.domain FROM web_domain WHERE web_domain.ssl ='y'";
        $result = mysqli_query($db, $sql) or die("Strange: query failed.\n") ;
        if (mysqli_num_rows($result)<1) die("No SSL-enabled domains. Nothing to do.\n");
        while ($row = mysqli_fetch_assoc($result))
                {if (file_exists("/etc/letsencrypt/live/{$row['domain']}/cert.pem"))
                        {$age = ((time()-filemtime("/etc/letsencrypt/live/{$row['domain']}/cert.pem"))/86400);
                        if ($age < 10) echo "Cert {$row['domain']} age {$age} days.\n"; else
                                letsencrypt($db,$row['domain'],$argv);
                }
                  else
                echo "External certificate for {$row['domain']}, skipping.\n";
        }

mysqli_free_result($result);}
$close = mysqli_close($db) or die("Strange shit happens: can't close DB.\n");



function letsencrypt($db,$ssldomain,$argv) {

$sql = "SELECT web_domain.document_root, web_domain.is_subdomainwww, web_domain.ssl, apache_directives FROM web_domain WHERE web_domain.domain = '{$ssldomain}'";
$result = mysqli_query($db, $sql) or die("Strange: query failed.\n");

$num = mysqli_num_rows($result);
if ($num <> 1) return "Found {$num} matches in database.\n";

$res = mysqli_fetch_array($result) or die("Strange shit happens: can't fetch array.\n");
mysqli_free_result($result);
$docroot = $res[0]."/web";
$sslroot = $res[0]."/ssl";
$apachedir = $res[3];
$webroot = docroot($docroot,$apachedir);

if (file_exists($webroot)) echo "Found DocumentRoot for {$ssldomain}: ".$webroot."\n"; else return "Fatal error: DocumentRoot {$webroot} doesn't exist\n";
if ($res[2] == 'n' AND !isset($argv[2])) return "Error: SSL not enabled for this domain. Use -f to forcebly enable SSL in database.\n"; else
        if (isset($argv[2]) AND $argv[2]!=="-f") return "Error: SSL not enabled for this domain. Use only -f to forcebly enable SSL in database.\n";

if (file_exists($sslroot."/".$ssldomain.".crt") AND file_exists($sslroot."/".$ssldomain.".key") AND file_exists($sslroot."/".$ssldomain.".bundle"))
        {if (!file_exists("/etc/letsencrypt/live/{$ssldomain}/cert.pem") OR !file_exists("/etc/letsencrypt/live/{$ssldomain}/privkey.pem") OR !file_exists("/etc/letsencrypt/live/{$ssldomain}/chain.pem"))
                return "This site don't use LetsEncrypt certificate, can't continue.\n"; else

                        if (!is_link("{$sslroot}/{$ssldomain}.crt") OR !is_link("{$sslroot}/{$ssldomain}.key") OR !is_link("{$sslroot}/{$ssldomain} .bundle"))
                        {

                                $output = shell_exec("rm -f {$sslroot}/{$ssldomain}.crt {$sslroot}/{$ssldomain}.key {$sslroot}/{$ssldomain}.bundle");
                                $output = shell_exec("ln -s /etc/letsencrypt/live/{$ssldomain}/cert.pem {$sslroot}/{$ssldomain}.crt");
                                $output = shell_exec("ln -s /etc/letsencrypt/live/{$ssldomain}/privkey.pem {$sslroot}/{$ssldomain}.key");
                                $output = shell_exec("ln -s /etc/letsencrypt/live/{$ssldomain}/chain.pem {$sslroot}/{$ssldomain}.bundle");
                                echo $output;
                        };
        };

if (isset($argv[3]) AND $argv[3]=='-r')
        {$slash="/";
        $apachedirdefault = "RewriteEngine On\nRewriteCond %"."{"."HTTPS} off\nRewriteRule (.*) https:{$slash}{$slash}%{"."HTTP_HOST"."}%"."{"."REQUEST_URI"."}"."\n";

        if (empty($apachedir)) $apachedir = $apachedirdefault; else                         
                if ($apachedir!==$apachedirdefault)
                        echo "Apache directive section contains:\n\n{$apachedir}\n\n You should manually update Apache Directives via WEB GUI:\n\n{$apachedirdefault}";
        };

$dir1 = $webroot."/.well-known/";
$dir2 = $webroot."/.well-known/acme-challenge/";
$account = "letsencrypt@admin.lv";

if ($res[1] = 1) $wwwdomain = " -d www.{$ssldomain}"; else $wwwdomain = "";

if (!mkdir($dir1)) echo "Creation of {$dir1} failed.\n";
if (!mkdir($dir2)) echo "Creation of {$dir2} failed.\n";
if (file_exists("{$webroot}/.htaccess")) rename("{$webroot}/.htaccess", "{$webroot}/letsencrypt.htaccess");
$output = shell_exec("/root/letsencrypt/letsencrypt-auto certonly -m {$account}  --renew-by-default -a webroot -w {$webroot}  --agree-tos -d {$ssldomain}{$wwwdomain}");
if (file_exists("{$webroot}/letsencrypt.htaccess")) rename("{$webroot}/letsencrypt.htaccess", "{$webroot}/.htaccess");
echo "Running {$output}\n";
rmdir($dir2);
rmdir($dir1);

if (!file_exists("/etc/letsencrypt/live/{$ssldomain}/cert.pem") OR !is_file("/etc/letsencrypt/live/{$ssldomain}/cert.pem")) return "Something wrong: Cert file /etc/letsencrypt/live/{$ssldomain}/cert.pem not found.\n";
if (time()-filemtime("/etc/letsencrypt/live/{$ssldomain}/cert.pem") >100) return "Something wrong: Cert file /etc/letsencrypt/live/{$ssldomain}/cert.pem older than 100 seconds.\n";

$sslcert = file_get_contents("/etc/letsencrypt/live/{$ssldomain}/cert.pem");
if (file_exists("/etc/letsencrypt/live/{$ssldomain}/privkey.pem"))
        $sslkey = file_get_contents("/etc/letsencrypt/live/{$ssldomain}/privkey.pem");
        else return "Something wrong: Key file /etc/letsencrypt/live/{$ssldomain}/privkey.pem not found.\n";

if (file_exists("/etc/letsencrypt/live/{$ssldomain}/chain.pem"))
        $sslchain = file_get_contents("/etc/letsencrypt/live/{$ssldomain}/chain.pem");
        else return "Something wrong: Chain file /etc/letsencrypt/live/{$ssldomain}/chain.pem not found.\n";                                        

if ($argv[1] == 'cron' ) $sslaction = " "; else $sslaction = "ssl_action='save', ";

$sql = "UPDATE web_domain SET web_domain.ssl='y', {$sslaction} ssl_cert='{$sslcert}', ssl_bundle='{$sslchain}', ssl_key='{$sslkey}', apache_directives='{$apachedir}' WHERE web_domain.domain = '{$ssldomain}';";
if (!mysqli_query($db, $sql)) return "Strange: UPDATE query failed.\n";
# mysqli_free_result($result);
echo "Looks like everything is ok.\n";
}

function docroot($docroot, $apachedir) {
        if (empty($apachedir)) return $docroot;
        $text = explode(PHP_EOL, $apachedir);
        if (empty($text)) $text = $apachedir;
        $i = 0;
        $ret = $docroot;
        while (!empty($text[$i])) {
                if (strpos(strtolower($text[$i]), "documentroot") !== FALSE) $ret = str_ireplace("DocumentRoot ", "", $text[$i]);
                $i++;
                }
        if (strpos(strtolower($ret),"{"."docroot"."}") !== FALSE) $ret = trim(str_ireplace("{"."docroot"."}", $docroot, $ret));
        if (strpos(strtolower($ret),'"') !== FALSE) $ret = trim(str_ireplace('"', '', $ret));
return $ret;
}

?>
