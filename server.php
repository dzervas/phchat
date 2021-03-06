<?php
require_once("srv.func.inc");
//	Begin Configuration	//
$salt='b#^X-zWOUw9z*[V$WPj{QGKx=lp!V[9rfnyvW2]QV+b8+=Yre$Hk&Ixau%M_VCA5YqO^7DNNaY[4{rw}F#Ja=ew$M%*#onZh1Lpo6qSKr$vRZHUpvt#J[QVC9K+_B03Z';
$conf=array("addr"=>"127.0.0.1",
	"comm"=>"\$", // User command prefix ex. ">", so to execute a command: >help
	"acomm"=>"#", // Admin command prefix ex. "#", so to execute an administration command: #mkusr
	"prom"=>"@BrainF:~\$ ", // User prompt ex. "@mysrv:~#" will be username@mysrv:~#
	"fue"=>2, // Number of times to encrypt usernames and passwords (up to 4) ex. 2
	"dev"=>false,
	"devs"=>0, // The socket number to connect ex. 0 (don't change if you don't know what it is)
	"enc"=>false,
	"buff"=>409600,
	"port"=>4444, // Port to bind
	"max"=>20, // Maximum number of users
	"guest"=>true, // Allow guests
	"active"=>3, // 0=no registration, 1=activation by admin, 2=activation by e-mail, 3=no activation
	"version"=>"1.04"
);
// Command list
$cm=array('h'=>"help", // User command list
	'i'=>"info",
	'u'=>"chown",
	'p'=>"passwd",
	'q'=>"exit",
	'r'=>"register",
	'l'=>"login",
	'o'=>"logout",
	'a'=>array('k'=>"kill", //Admin command list
		'b'=>"ban",
		'c'=>"kick",
		'h'=>"help",
		'm'=>"mkusr",
		'r'=>"rmusr",
		'p'=>"passwd",
		'u'=>"chusr",
	)
);
$admins=array("admin", "dzerv"); // Administartors' usernames ex. "admin1", "admin2"
//	/!\ WARNING /!\		//
// Be very careful with the administartion list, as the administrators have the permissions
// 	to delete ban or kick users or even other administartors!

$dbconf=array("type"=>"mysql",		// "sqlite" for SQLite3 or "mysql" for MySQL DB
	"file"=>"./database.db",	// (SQLite3) Database file ex. "./mydatabase.db"
	"host"=>"127.0.0.1",		// (MySQL) Database host ex. "localhost"
	"user"=>"username",		// (MySQL) Database username ex. "mydatabaseuser"
	"pass"=>"password",		// (MySQL) Database password ex. "mydatabasepass"
	"db"=>"database"			// (MySQL) Database name ex. "mydatabase"
);
$sql_setup="CREATE TABLE users (user VARCHAR(100), pass VARCHAR(64), email VARCHAR(100), register INT(1));";
//	End Configuration		//

//	Begin Help Menu			//
$help ="Usage: ".basename($argv[0])." [-a 127.0.0.1] [-p 4444] [-gedsh]\n";
$help.="Options:\n";
$help.="-a 127.0.0.1, --address=127.0.0.1	Define the address to listen\n";
$help.="-p 4444, --port=4444			Define the port to listen\n";
$help.="-g, --guest				Enable or disable guest login\n";
$help.="-e, --enc				Encrypted connections*\n";
$help.="-d, --dev				Debugging mode**\n";
$help.="-s, --setup				Setup the database, will create tables needed by server (it won't delete anything)\n";
$help.="-h, --help				Display this help message\n";
#$help.="*: By encrypting the connection, the text sent is encrypted, NOT SSL connection...\n";
#$help.="**: In this mode, if you connect to socket 0, you will be logged in, as admin\n\twithout ANY authentication\n";
#$help.="!!! WARNING !!! Be very careful with debugging mode!!!!\n";
$help.="Example: server -a 127.0.0.1 -p 4444\n";
//	End Help Menu			//

//	Begin DataBase Initiation	//
if ($dbconf["type"]=="mysql") {
	require_once("mysql.class.inc");
	$db=new DB();
	$db->host=$dbconf['host'];
	$db->user=$dbconf['user'];
	$db->password=$dbconf['pass'];
	$db->database=$dbconf['db'];
	$db->open();
} else {
	require_once("sqlite.class.inc");
	$db=new DB();
	$db->database=$dbconf['file'];
	$db->open();
}
//	End DataBase Initiation		//

// No timeouts, flush content immediatly
set_time_limit(0);
ob_implicit_flush();
$key=fue($salt);
$client=array();
$read=array();

//	Beggin User Options Catch	//
$opts=getopt("p:a:egdhs",array("port:","address:","enc","dev","help", "guest", "setup"));
if(isset($opts['p']) && isset($opts["port"])) outp("Please use only -p or --port",'e');
elseif(isset($opts['p'])) $conf["port"]=$opts['p'];
elseif(isset($opts["port"])) $conf["port"]=$opts["port"];

if(isset($opts['a']) && isset($opts["addr"])) outp("Please use only -a or --address",'e');
elseif(isset($opts['a'])) $conf["addr"]=$opts['a'];
elseif(isset($opts["address"])) $conf["addr"]=$opts["address"];

if(isset($opts['g']) && isset($opts["guest"])) outp("Please use only -g or --guest",'e');
elseif(isset($opts['g']) || isset($opts["guest"])) $conf["guest"]=change($conf["guest"]);

if(isset($opts['e']) && isset($opts["enc"])) outp("Please use only -e or --enc",'e');
elseif(isset($opts['e']) || isset($opts["enc"])) $conf["enc"]=change($conf["enc"]);

if(isset($opts['d']) && isset($opts["dev"])) outp("Please use only -d or --dev",'e');
elseif(isset($opts['d']) || isset($opts["dev"])) $conf["dev"]=change($conf["dev"]);

if(isset($opts['h']) && isset($opts["help"])) outp("Please use only -h or --help",'e');
elseif(isset($opts['h']) || isset($opts["help"])) { echo $help; exit(0); }

if(isset($opts['s']) && isset($opts["setup"])) outp("Please use only -s or --setup",'e');
elseif(isset($opts['s']) || isset($opts["setup"])) $db->query($sql_setup);
//	End User Options Catch		//

//	Begin Socket Proccessing	//
$sock=socket_create(AF_INET,SOCK_STREAM,0) or outp("Could not create socket",'e');
socket_bind($sock,$conf["addr"],$conf["port"]) or outp("Could not bind to socket",'e');
socket_listen($sock, 100) or outp("Could not set up socket listener",'e');
if($conf['enc']==true) outp("Server started at ".$conf["addr"].":".$conf["port"]." encrypted","i");
else outp("Server started at ".$conf["addr"].":".$conf["port"]." unencrypted","i");
if($conf['dev']==true) outp("Server in devlopment mode (socket ".$conf["devs"]." will be admin)","i");
//	End Socket Proccessing		//

//	Begin Server Loop	//
while(true) {
	socket_set_block($sock);
	$read[0]=$sock; // Setup clients listen socket for reading
	for($i=0;$i<$conf['max'];$i++)
		if($client[$i]['sock']!=null)
			$read[$i+1]=$client[$i]['sock'];
	$ready=socket_select($read,$write=NULL,$except=NULL,$tv_sec=NULL);

	// If a new connection is being made add it to the clients array
	if(in_array($sock,$read)) {
		for($i=0;$i<$conf['max'];$i++) {
			if($client[$i]['sock']==null) {
				if(!$client[$i]['sock']=socket_accept($sock))
					outp("socket_accept() failed: ".socket_strerror($client[$i]['sock']),'x');
				elseif($i==0 && $conf['dev']==true) outp("Client connected at socket ".$i." (admin)",'!');
				else outp("Client connected at socket ".$i,'!');
				break;
			}elseif($i==$conf['max']-1) outp("Too many clients",'x');
		}
		if(--$ready<=0) continue;
	}

	for($i=0;$i<$conf['max'];$i++) {
		if(in_array($client[$i]['sock'],$read)) {
			if($conf['dev']==true)
				$usr[$conf["devs"]]["name"]="admin";
			if(isset($usr[$i]["name"])) {
				$input=socket_cread($client[$i]['sock']);
				$n=trim($input);
				$com=split(" ",$n);
				$nopt=str_getopts($n,1);
				$admopt=str_getopts($n,3);
			}

			if(!isset($usr[$i]["name"]) || $usr[$i]["name"]=="ERR" || $n==$conf['comm'].$cm['l'] && !isset($login[$i])) {
				if($conf["guest"]==false || $n==$conf['comm'].$cm['l']) {
					socket_cwrite($client[$i]['sock'],"Login: ");
					$login[$i]=socket_cread($client[$i]['sock']);
					socket_cwrite($client[$i]['sock'],"Pass: ");
					$pass=socket_cread($client[$i]['sock']);

					if(check($login[$i],$pass,$client[$i]['sock'])!="ERR") {
						$usr[$i]["name"]=check($login[$i],$pass,$client[$i]['sock']);
						if(doubleu($i)=="ERR") {
							socket_close($client[$i]['sock']);
							unset($client[$i]['sock']);
							outp("Double login! Socket: ".$i.", User: ".strip($login[$i]),'x');
						}else{
							socket_cwrite($client[$i]['sock'],"\nWelcome ".$usr[$i]["name"]."!\n");
							socket_cwrite($client[$i]['sock'],"For help, type \$help\n");
							$usr[$i]["prom"]=$usr[$i]["name"].$conf["prom"];
							for($x=0;$x<count($client);$x++) {
								if($client[$x]['sock']!=$client[$i]['sock']) {
					socket_cwrite($client[$x]['sock'],"\n\tServer: User ".$usr[$i]["name"]." connected!\n");
					socket_cwrite($client[$x]['sock'],$usr[$x]["prom"]);
								}
							}
						}
					}
				}else{
					$usr[$i]["name"]="guest".$i;
					$usr[$i]["prom"]=$usr[$i]["name"].$conf["prom"];
					socket_cwrite($client[$i]['sock'],"\nWelcome ".$usr[$i]["name"]."!\n");
					socket_cwrite($client[$i]['sock'],"For help, type \$help\n");
				}
			}elseif($n==null && isset($usr[$i]["name"]))
				NULL;
			elseif($n==$conf['comm'].$cm['o']) {
				unset($login[$i]);
				outp("User ".strip($usr[$i]["name"])." logged out",'i');
				$usr[$i]["name"]="guest".$i;
				$usr[$i]["prom"]=$usr[$i]["name"].$conf["prom"];

			}elseif($n==$conf['comm'].$cm['r']) {
				socket_cwrite($client[$i]['sock'],"\nUsername: ");
				$user=socket_cread($client[$i]['sock']);
				socket_cwrite($client[$i]['sock'],"\nPassword: ");
				$pass=socket_cread($client[$i]['sock']);
				socket_cwrite($client[$i]['sock'],"\nE-Mail (MUST be real, will not be published): ");
				$email=socket_cread($client[$i]['sock']);
				register($user, $pass, $email);
			}elseif($n==$conf['comm'].$cm['h']) {
				// Help requested
				socket_cwrite($client[$i]['sock'],"\n+-=-=-=-=-=-+-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-+");
				socket_cwrite($client[$i]['sock'],"\n|  Command  | Description\t\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n+-=-=-=-=-=-+-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-+");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['h']."    | Display this help message\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['i']."    | Show server's info and online users\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['r']."    | Register to the server\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['l']."    | Login to the server   \t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  @<user>  | Send private message to <user>\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|\t    | \t(where <user> is the username)\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['u']."   | Change your username\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['p']."  | Change your password\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['o']."    | Logout from the server\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n|  ".$conf['comm'].$cm['q']."    | Close the connection\t\t\t|");
				socket_cwrite($client[$i]['sock'],"\n+-=-=-=-=-=-+-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-+\n");
			}elseif($n==$conf['comm'].$cm['i']) {
				// Info requested
				socket_cwrite($client[$i]['sock'],"BrainF chat server v.".$conf['version']." by ttouch\n");
				socket_cwrite($client[$i]['sock'],"Online now:\n");
				for($x=0;$x<count($client);$x++) {
					if(isset($usr[$x]["name"])) socket_cwrite($client[$i]['sock'],"\t".$usr[$x]["name"]."\n");
				}
				socket_cwrite($client[$i]['sock'],"For help type ".$conf['comm']."help\n");
			}elseif(strstr($nopt[0],"@")) {
				if(in_array(str_replace("@","",$nopt[0]),$usr)) {
					// Private message
					$output=ereg_replace("[\t\n\r]","",$input).chr(0);
					$pm=PM($output,$i);
					if($pm=="ERR") socket_cwrite($client[$i]['sock'],"\tMessage could not be sent\n");
				}else socket_cwrite($client[$i]['sock'],"\t".$nopt[0]." is not a command  or username\n");
			}elseif($n==$conf['comm'].$cm['u']) {
				// Username change requested
				socket_cwrite($client[$i]['sock'],"Password: ");
				$oldu=socket_cread($client[$i]['sock']);
				socket_cwrite($client[$i]['sock'],"New username: ");
				$newu=socket_cread($client[$i]['sock']);
				socket_cwrite($client[$i]['sock'],"Retype new username: ");
				$newu2=socket_cread($client[$i]['sock']);

				if($newu==$newu2) {
					$ch=newdt($usr[$i]["name"],$oldp,$newu);
					if($ch=="OK") {
						socket_cwrite($client[$i]['sock'],"Username changed!\n");
						$usr[$i]["name"]=$newu;
					}elseif ($ch=="ERR1") socket_cwrite($client[$i]['sock'],"Empty username or password given!\n");
					else socket_cwrite($client[$i]['sock'],"Error on username change!\n");
				}else socket_cwrite($client[$i]['sock'],"Usernames do not match!\n");
			}elseif($n==$conf['comm'].$cm['p']) {
				// Password change requested
				socket_cwrite($client[$i]['sock'],"Old password: ");
				$oldp=socket_cread($client[$i]['sock']);
				if(check($usr[$i]["name"],$oldp,$client[$i]['sock'],"pass")=="OK") {
					socket_cwrite($client[$i]['sock'],"New password: ");
					$newp=socket_cread($client[$i]['sock']);
					socket_cwrite($client[$i]['sock'],"Retype new password: ");
					$newp2=socket_cread($client[$i]['sock']);
				}else{
					socket_cwrite($client[$i]['sock'],"Wrong password!\n");
					break;
				}
				if($newp==$newp2 && $err!=1) {
					$ch=newdt($usr[$i]["name"],$oldp,NULL,$newp);
					if($ch=="OK") {
						socket_cwrite($client[$i]['sock'],"Password changed!\n");
						$usr[$i]["name"]=NULL;
					}else socket_cwrite($client[$i]['sock'],"Error on password change!\n");
				}else socket_cwrite($client[$i]['sock'],"Passwords do not match!\n");
			}elseif($n==$conf['comm'].$cm['q']) {
				if($client[$i]['sock']!=null) {
					// Disconnect requested
					socket_close($client[$i]['sock']);
					unset($client[$i]['sock']);
					outp("Client disconnected at socket ".$i,'x');
					for($x=0;$x<count($client);$x++) {
						socket_cwrite($client[$x]['sock'],"\n\t".$usr[$i]["name"]." disconnected!\n");
						socket_cwrite($client[$x]['sock'],$usr[$i]["prom"]);
					}
					$usr[$i]["name"]=NULL;
					if($i==$adm) $adm=-1;
				}
			}elseif(strstr($nopt[0],$conf['comm']) && !in_array(str_replace($conf['comm'],"",$n),$cm)) {
				socket_cwrite($client[$i]['sock'],"\tServer: ".$nopt[0]." is not a command  or username\n");
			//	Begin Admin Section	//
			}elseif($admopt[0]==$conf['acomm'].$cm['a']['k'] && in_array($usr[$i]["name"],$admins)) {
				// Terminate server
				for($x=0;$x<count($client);$x++) {
					socket_cwrite($client[$x]['sock'],"\nAdmin terminated the server...\n");
				}
				socket_close($sock);
				unset($sock);
				outp("Server terminated by admin",'e');
			}elseif($admopt[0]==$conf['acomm'].$cm['a']['h'] && in_array($usr[$i]["name"],$admins)) {
				socket_cwrite($client[$i]['sock'],"\n\t\t+-=-=-=-=-+");
				socket_cwrite($client[$i]['sock'],"\n\t\t| Select  |");
				socket_cwrite($client[$i]['sock'],"\n\t\t+-=-=-=-=-+");
				socket_cwrite($client[$i]['sock'],"\n\t\t| ".$cm['a']['k']."\t  |");
				socket_cwrite($client[$i]['sock'],"\n\t\t| ".$cm['a']['m']." <user> <pass>|");
				socket_cwrite($client[$i]['sock'],"\n\t\t| ".$cm['a']['r']." <user>|");
				socket_cwrite($client[$i]['sock'],"\n\t\t| ".$cm['a']['p']." <user> <pass>|");
				socket_cwrite($client[$i]['sock'],"\n\t\t| ".$cm['a']['u']." <user> <newuser>|");
				socket_cwrite($client[$i]['sock'],"\n\t\t+-=-=-=-=-+\n");
			}elseif($admopt[0]==$conf['acomm'].$cm['a']['m'] && in_array($usr[$i]["name"],$admins)) {
				$add=newdt(NULL,NULL,$admopt[1],$admopt[2],"amake");
				if($add=="OK") socket_cwrite($client[$i]['sock'],"User ".trim($admopt[1])." created\n");
				else socket_cwrite($client[$i]['sock'],"Error on user creation!");

			}elseif($admopt[0]==$conf['acomm'].$cm['a']['r'] && in_array($usr[$i]["name"],$admins)) {
				$ch=newdt($user,"i'm",$admopt[1],"delete");
				if($ch=="OK") socket_cwrite($client[$i]['sock'],"User removed!\n");
				else socket_cwrite($client[$i]['sock'],"Error!\n");

			}elseif($admopt[0]==$conf['acomm'].$cm['a']['p'] && in_array($usr[$i]["name"],$admins)) {
				$ch=newdt($admopt[1],"i'm","admin",$admopt[2]);
				if($ch=="OK") socket_cwrite($client[$i]['sock'],"Password changed!\n");
				else socket_cwrite($client[$i]['sock'],"Error!\n");

			}elseif($admopt[0]==$conf['acomm'].$cm['a']['u'] && in_array($usr[$i]["name"],$admins)) {
				$ch=newdt($admopt[1],"i'm",$admopt[2],"admin");
				if($ch=="OK") socket_cwrite($client[$i]['sock'],"Username changed!\n");
				else socket_cwrite($client[$i]['sock'],"Error!\n");
			//	End Admin Section	//

			}elseif($input) {
				$output=ereg_replace("[\t\n\r]","",$input).chr(0);
				if($input!=NULL) {
					for($x=0;$x<count($client);$x++) {
						if($client[$x]['sock']!=$client[$i]['sock']) {
						socket_cwrite($client[$x]['sock'],"\n\t".$usr[$i]["name"].": ".$output."\n");
						socket_cwrite($client[$x]['sock'],$usr[$x]["prom"]);
						}else socket_cwrite($client[$i]['sock'],"\n\tYou: ".$output."\n");
					}
				}
			}
			socket_cwrite($client[$i]['sock'],$usr[$i]["prom"]);
		}
	}
}
//	End Server Loop		//

// Close the master sockets
socket_close($sock);
?>`
