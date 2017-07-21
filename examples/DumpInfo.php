<?php

/**
 * @name DumpServerInfo
 * @main robske_110\DPS\DumpServerInfo
 * @version 1.0.0
 * @api 3.0.0-ALPHA7
 * @description Dumps query info of a Server using SignServerStats
 * @author robske_110
 * @license MIT
 */


namespace robske_110\DPS{

	use robske_110\SPC\ScriptPluginCommands;
	use robske_110\SSS\SignServerStats;
	use pocketmine\event\Listener;
	use pocketmine\plugin\PluginBase;
	use pocketmine\event\server\DataPacketReceiveEvent;
	use pocketmine\network\protocol\MovePlayerPacket;
	use pocketmine\command\Command;
	use pocketmine\command\CommandSender;
	use pocketmine\utils\TextFormat as TF;
	use pocketmine\scheduler\PluginTask;

	class DumpServerInfo extends PluginBase{
		
		const API_VERSION = "1.0.0";
		
		/** @var DisplayTask */
		private $displayTask;
		
		public function onLoad(){
			$id = ScriptPluginCommands::initFor($this);
			ScriptPluginCommands::addCommand($id, [
				'name' => 'dumpinfo',
				'description' => 'Dumps query information from the specified server.',
				'permission' => 'op',
				'usage' => '/dumpinfo <hostname> [port]'
			]);
			$this->getServer()->getCommandMap()->registerAll($this->getDescription()->getName(), ScriptPluginCommands::getCommands($id));
		}
		
		public function onEnable(){
			if(($sss = $this->getSSS()) !== NULL){
				if(!$sss->isCompatible(self::API_VERSION)){
					$newOld = version_compare(self::API_VERSION, SignServerStats::API_VERSION, ">") ? "old" : "new";
					$this->getLogger()->critical("Your version of SignServerStats is too ".$newOld." for this plugin.");
					$this->getServer()->getPluginManager()->disablePlugin($this);
					return;
				}
			}else{
				$this->getLogger()->critical("This plugin needs SignServerStats. And I couldn't find it :/");
				$this->getServer()->getPluginManager()->disablePlugin($this);
				return;
			}
			$this->displayTask = new DisplayTask($this);
			$this->getServer()->getScheduler()->scheduleRepeatingTask($this->displayTask, 10);
		}
		
		public function getSSS(){
			if(($sss = $this->getServer()->getPluginManager()->getPlugin("SignServerStats")) instanceof SignServerStats){
				return $sss;
			}else{
				$this->getLogger()->critical("Unexpected error: Trying to get SignServerStats plugin instance failed!");
				return NULL;
			}
		}
		
		public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
			if($command->getName() == "dumpinfo"){
				if(isset($args[0])){
					$hostname = $args[0];
					$port = 19132;
					if(isset($args[1])){
						if(is_numeric($args[1])){
							$port = $args[1];
						}else{
							return false;
						}
					}
					if(($sss = $this->getSSS()) !== NULL){
						$sss->addServer($hostname, $port);
						$this->displayTask->addServer($hostname, $port, $sender);
						$sender->sendMessage("Getting info for the server ".$hostname.":".$port."...");
					}else{
						$sender->sendMessage("SSS is not enabled anymore, it might have crashed.");
					}
					return true;
				}
				return false;
			}
		}
	}
	
	class DisplayTask extends PluginTask{
		private $plugin;
		private $checkServers = [];
	
	    public function __construct(DumpServerInfo $plugin){
	        parent::__construct($plugin);
	        $this->plugin = $plugin;
	    }
	
		public function addServer(string $hostname, int $port, CommandSender $sender){
			$this->checkServers[] = [$hostname, $port, $sender];
		}
	
		public function onRun(int $currentTick){
			$sss = $this->plugin->getSSS();
			if($sss === NULL){
				$this->plugin->getLogger()->critical("SSS is not enabled anymore, it may have crashed.");
				return;
			}
			foreach($this->checkServers as $index => $server){
				if(($msgs = $this->dumpServer($server[0], $server[1], $sss)) !== []){
					foreach($msgs as $msg){
						$server[2]->sendMessage($msg);
					}
					unset($this->checkServers[$index]);
					$sss->removeServer($server[0], $server[1]); //Warning: In future versions of SSS it could also immediately remove data, therfore breaking multiple requests at once.
				}
			}
			$this->checkServers = array_values($this->checkServers);
		}
	
		private function dumpServer($hostname, $port, SignServerStats $sss): array{
			$msgs = [];
			$serverOnlineArray = $sss->getServerOnline();
			if(isset($serverOnlineArray[$hostname."@".$port])){
				$msgs[] = "Dump for server ".$hostname.":".$port.":";
			    $isOnline = $serverOnlineArray[$hostname."@".$port];
			    if($isOnline){
		    		$msgs[] = "Status: Online";
					$msgs[] = "MODT: ".$sss->getMODTs()[$hostname."@".$port].TF::RESET;
					$playerData = $sss->getPlayerData()[$hostname."@".$port];
					$msgs[] = "Players: ".$playerData[0]."/".$playerData[1];
			    }else{
			    	$msgs[] = "Status: Offline";
			    }
			}
			return $msgs;
		}
	}
}
/** LIBARIES */
namespace robske_110\SPC{
	use pocketmine\command\PluginCommand;
	use pocketmine\Plugin\Plugin;
	use robske_110\Utils\Utils;
	
	/**
	  * @author robske_110
	  * @version 0.1.2-php7 
	  */
	abstract class ScriptPluginCommands{
		private static $plugins;
		
		public static function initFor(Plugin $plugin) : int {
			self::$plugins[] = [$plugin];
			return count(self::$plugins) - 1;
		}
		
		public static function addCommand(int $id, array $data){
			if(!isset(self::$plugins[$id])){
				Utils::critical("addCommand() has been called with an unkown ID!");
			}
			$cmd = new PluginCommand($data["name"], self::$plugins[$id][0]);
			if(isset($data["description"])){
				$cmd->setDescription($data["description"]);
			}
			if(isset($data["usage"])){
				$cmd->setUsage($data["usage"]);
			}
			if(isset($data["permission"])){
				$cmd->setPermission($data["permission"]);
			}
			if(isset($data["aliases"]) && is_array($data["aliases"])){
				$aliases = [];
				foreach($data["aliases"] as $alias){
					$aliases[] = $alias;
				}
				$cmd->setAliases($aliases);
			}
			self::$plugins[$id][1][] = $cmd;
		}
		
		public static function getCommands(int $id) : array {
			if(!isset(self::$plugins[$id])){
				Utils::critical("getCommands() has been called with an unkown ID!");
			}
			return self::$plugins[$id][1];
		}
	}
}