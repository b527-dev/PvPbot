<?php

namespace pvpbot;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\utils\Config;
use Throwable;

class Main extends PluginBase implements Listener {

    /** @var Human[] $bots [playerName => Human] */
    private array $bots = [];
    /** @var string[] $botOwners [objectId => playerName] */
    private array $botOwners = [];
    /** @var string[] $botModes [objectId => mode] */
    private array $botModes = [];

    private Config $cfg;
    private string $skinData = "";
    private string $skinId = "";

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->cfg = $this->getConfig();

        $skinFile = $this->cfg->getNested("bot.skin", "bot_skin.png");
        $this->saveResource($skinFile, true);
        $this->loadSkin();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        if ($this->cfg->get("save-bot-position", true)) {
            $this->loadSavedBots();
        }

        // Обновление каждые 2 тика (~0.1 сек)
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends \pocketmine\scheduler\Task {
            public function __construct(private Main $plugin) {}
            public function onRun(): void {
                $this->plugin->updateBots();
            }
        }, 2);
    }

    private function loadSkin(): void {
        $skinFileName = $this->cfg->getNested("bot.skin", "bot_skin.png");
        $skinPath = $this->getDataFolder() . $skinFileName;

        if (!file_exists($skinPath)) {
            $this->getLogger()->warning("Скин не найден: $skinPath");
            return;
        }

        if (!extension_loaded("gd")) {
            $this->getLogger()->error("GD не загружен! Скин не будет применён.");
            return;
        }

        $image = @imagecreatefrompng($skinPath);
        if (!$image) {
            $this->getLogger()->error("Не удалось загрузить PNG: $skinPath");
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width !== 64 || ($height !== 32 && $height !== 64)) {
            $this->getLogger()->error("Неверный размер скина. Требуется 64x32 или 64x64.");
            imagedestroy($image);
            return;
        }

        $data = "";
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $a = ($rgba >> 24) & 0xff;
                $r = ($rgba >> 16) & 0xff;
                $g = ($rgba >> 8) & 0xff;
                $b = $rgba & 0xff;
                $data .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }

        imagedestroy($image);
        $this->skinData = $data;
        $this->skinId = hash("sha256", $data);
        $this->getLogger()->info("Скин загружен: {$width}x{$height}");
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Human && !$event->isCancelled()) {
            foreach ($this->bots as $bot) {
                if ($bot === $entity) {
                    if ($event instanceof EntityDamageByEntityEvent) {
                        $damager = $event->getDamager();
                        if ($damager instanceof Player) {
                            $knockback = (float)$this->cfg->getNested("bot.knockback-bot", 0.3);
                            $direction = $bot->getPosition()->subtractVector($damager->getPosition())->normalize();
                            $bot->setMotion($direction->multiply($knockback));
                        }
                    }

                    $this->getScheduler()->scheduleDelayedTask(new class($bot) extends \pocketmine\scheduler\Task {
                        public function __construct(private Human $bot) {}
                        public function onRun(): void {
                            if (!$this->bot->isClosed()) {
                                $this->bot->setHealth(20.0);
                            }
                        }
                    }, 1);

                    return;
                }
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cТолько в игре.");
            return true;
        }

        if (empty($args)) {
            $sender->sendMessage("§7Используй: /pvpbot <spawn|remove|mode|list>");
            return true;
        }

        $sub = strtolower($args[0]);

        if ($sub === "spawn") {
            if (!$sender->hasPermission($this->cfg->getNested("permissions.spawn", "pvpbot.command.spawn"))) {
                $sender->sendMessage("§cНет прав.");
                return true;
            }
            $this->spawnBotFor($sender);
            return true;
        }

        if ($sub === "remove") {
            if (!$sender->hasPermission($this->cfg->getNested("permissions.remove", "pvpbot.command.remove"))) {
                $sender->sendMessage("§cНет прав.");
                return true;
            }
            if (!isset($args[1])) {
                $this->removeBotFor($sender);
                return true;
            }
            $targetName = $args[1];
            $this->removeBotByName($sender, $targetName);
            return true;
        }

        if ($sub === "mode") {
            if (!$sender->hasPermission($this->cfg->getNested("permissions.mode", "pvpbot.command.mode"))) {
                $sender->sendMessage("§cНет прав.");
                return true;
            }
            if (!isset($args[1])) {
                $sender->sendMessage("§7Режимы: training, aggressive, passive");
                return true;
            }
            $mode = strtolower($args[1]);
            if (!in_array($mode, ["training", "aggressive", "passive"], true)) {
                $sender->sendMessage("§cНеверный режим. Используй: training, aggressive, passive");
                return true;
            }
            $this->setBotMode($sender, $mode);
            return true;
        }

        if ($sub === "list") {
            if (!$sender->hasPermission($this->cfg->getNested("permissions.list", "pvpbot.command.list"))) {
                $sender->sendMessage("§cНет прав.");
                return true;
            }
            $this->showBotList($sender);
            return true;
        }

        $sender->sendMessage("§7Неизвестный аргумент. Используй: spawn, remove, mode, list");
        return true;
    }

    private function spawnBotFor(Player $player): void {
        $name = $player->getName();
        if (isset($this->bots[$name])) {
            $player->sendMessage("§cУ тебя уже есть бот!");
            return;
        }

        $location = $player->getLocation();
        $location->x += 2;

        if ($this->skinData !== "") {
            $skin = new Skin("Standard", $this->skinData, "", "geometry.humanoid.custom", "");
        } else {
            $skin = new Skin("Standard", str_repeat("\x00", 64 * 32 * 4));
        }

        $nbt = CompoundTag::create()
            ->setFloat("Health", 20.0)
            ->setFloat("MaxHealth", 20.0);

        $bot = new Human($location, $skin, $nbt);
        $bot->setNameTag($this->cfg->getNested("bot.name", "§l§cPvP Bot"));
        $bot->setScale((float)$this->cfg->getNested("bot.scale", 1.0));
        $bot->spawnToAll();

        $bot->setHealth(20.0);
        $bot->setMaxHealth(20.0);

        $objectId = spl_object_id($bot);
        $this->botOwners[$objectId] = $name;
        $this->botModes[$objectId] = "training";

        $this->bots[$name] = $bot;
        $player->sendMessage("§aБот создан!");

        if ($this->cfg->get("save-bot-position", true)) {
            $this->saveBotPosition($name, $bot->getPosition(), $player->getWorld());
        }
    }

    private function removeBotFor(Player $player): void {
        $name = $player->getName();
        if (!isset($this->bots[$name])) {
            $player->sendMessage("§cУ тебя нет бота.");
            return;
        }

        $bot = $this->bots[$name];
        $objectId = spl_object_id($bot);

        $bot->flagForDespawn();
        unset(
            $this->bots[$name],
            $this->botOwners[$objectId],
            $this->botModes[$objectId]
        );

        if ($this->cfg->get("save-bot-position", true)) {
            $this->removeSavedBot($name);
        }

        $player->sendMessage("§aТвой бот удалён.");
    }

    private function removeBotByName(Player $player, string $botOwner): void {
        if (!isset($this->bots[$botOwner])) {
            $player->sendMessage("§cБота с владельцем '$botOwner' не существует.");
            return;
        }

        $bot = $this->bots[$botOwner];
        $objectId = spl_object_id($bot);

        $bot->flagForDespawn();
        unset(
            $this->bots[$botOwner],
            $this->botOwners[$objectId],
            $this->botModes[$objectId]
        );

        if ($this->cfg->get("save-bot-position", true)) {
            $this->removeSavedBot($botOwner);
        }

        $player->sendMessage("§aБот '$botOwner' удалён.");
    }

    private function setBotMode(Player $player, string $mode): void {
        $name = $player->getName();
        if (!isset($this->bots[$name])) {
            $player->sendMessage("§cСначала создай бота (/pvpbot spawn).");
            return;
        }

        $bot = $this->bots[$name];
        $objectId = spl_object_id($bot);
        $this->botModes[$objectId] = $mode;

        $bot->setNameTag("§l§cPvP Bot §7[" . $mode . "]");
        $player->sendMessage("§eРежим: §f" . $mode);
    }

    private function showBotList(Player $player): void {
        if (empty($this->bots)) {
            $player->sendMessage("§eНет активных ботов.");
            return;
        }

        $player->sendMessage("§aАктивные боты:");
        foreach ($this->bots as $owner => $bot) {
            $objectId = spl_object_id($bot);
            $mode = $this->botModes[$objectId] ?? "unknown";
            $player->sendMessage("§7- §f$owner §7(Режим: §f$mode§7)");
        }
    }

    public function updateBots(): void {
        foreach ($this->bots as $ownerName => $bot) {
            if ($bot->isClosed()) {
                $objectId = spl_object_id($bot);
                unset($this->botOwners[$objectId], $this->botModes[$objectId]);
                unset($this->bots[$ownerName]);
                continue;
            }

            $owner = $this->getServer()->getPlayerExact($ownerName);
            if (!$owner || !$owner->isOnline()) continue;

            $objectId = spl_object_id($bot);
            $mode = $this->botModes[$objectId] ?? "training";
            if ($mode === "passive") continue;

            $playerPos = $owner->getPosition();
            $botPos = $bot->getPosition();
            $dist = $playerPos->distance($botPos);

            if ($dist > (float)$this->cfg->getNested("bot.follow-distance", 16.0)) continue;

            $bot->lookAt($playerPos);

            // Атака
            if ($dist <= (float)$this->cfg->getNested("bot.attack-distance", 2.5)) {
                $pk = ActorEventPacket::create($bot->getId(), 1, 0);
                NetworkBroadcastUtils::broadcastPackets($bot->getViewers(), [$pk]);

                if ($mode === "aggressive") {
                    $damage = (float)$this->cfg->getNested("bot.damage", 0.0);
                    if ($damage > 0) {
                        $owner->attack($damage);

                        $knockback = (float)$this->cfg->getNested("bot.knockback-player", 0.4);
                        $direction = $owner->getPosition()->subtractVector($bot->getPosition())->normalize();
                        $owner->setMotion($direction->multiply(-$knockback));
                    }
                }
            }

            // Движение с физикой через setMotion
            if ($dist > 0.1) {
                $speed = (float)$this->cfg->getNested("bot.speed", 1.0); // из конфига
                $direction = $playerPos->subtractVector($botPos)->normalize();
                $bot->setMotion($direction->multiply($speed));
            } else {
                $bot->setMotion(new Vector3(0, 0, 0));
            }
        }
    }

    private function saveBotPosition(string $owner, Vector3 $pos, World $world): void {
        $this->cfg->set("saved-bot." . $owner, [
            "x" => $pos->x,
            "y" => $pos->y,
            "z" => $pos->z,
            "world" => $world->getFolderName()
        ]);
        $this->cfg->save();
    }

    private function removeSavedBot(string $owner): void {
        $this->cfg->remove("saved-bot." . $owner);
        $this->cfg->save();
    }

    private function loadSavedBots(): void {
        $saved = $this->cfg->get("saved-bot", []);
        if (!is_array($saved)) return;

        foreach ($saved as $owner => $data) {
            if (!isset($data["world"], $data["x"], $data["y"], $data["z"])) continue;

            $world = $this->getServer()->getWorldManager()->getWorldByName($data["world"]);
            if ($world === null) continue;

            $location = new Location($data["x"], $data["y"], $data["z"], 0.0, 0.0, $world);

            if ($this->skinData !== "") {
                $skin = new Skin("Standard", $this->skinData, "", "geometry.humanoid.custom", "");
            } else {
                $skin = new Skin("Standard", str_repeat("\x00", 64 * 32 * 4));
            }

            $nbt = CompoundTag::create()
                ->setFloat("Health", 20.0)
                ->setFloat("MaxHealth", 20.0);

            $bot = new Human($location, $skin, $nbt);
            $bot->setNameTag($this->cfg->getNested("bot.name", "§l§cPvP Bot"));
            $bot->setScale((float)$this->cfg->getNested("bot.scale", 1.0));
            $bot->spawnToAll();

            $bot->setHealth(20.0);
            $bot->setMaxHealth(20.0);

            $objectId = spl_object_id($bot);
            $this->botOwners[$objectId] = $owner;
            $this->botModes[$objectId] = "training";

            $this->bots[$owner] = $bot;
        }
    }
}