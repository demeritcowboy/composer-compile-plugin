<?php

namespace Civi\CompilePlugin;

use Civi\CompilePlugin\Command\CompileListCommand;
use Civi\CompilePlugin\Event\CompileEvents;
use Civi\CompilePlugin\Subscriber\PhpSubscriber;
use Civi\CompilePlugin\Subscriber\ShellSubscriber;
use Civi\CompilePlugin\Util\TaskUIHelper;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class CompilePlugin implements PluginInterface, EventSubscriberInterface, Capable
{

    /**
     * @var \Composer\Composer
     */
    private $composer;

    /**
     * @var \Composer\IO\IOInterface
     */
    private $io;

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => ['validateMode', 5],
            ScriptEvents::PRE_UPDATE_CMD => ['validateMode', 5],
            ScriptEvents::POST_INSTALL_CMD => ['runTasks', 5],
            ScriptEvents::POST_UPDATE_CMD => ['runTasks', 5],
        ];
    }

    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => CommandProvider::class,
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $dispatch = $composer->getEventDispatcher();
        $dispatch->addListener(CompileEvents::POST_COMPILE_LIST, [ShellSubscriber::class, 'applyDefaultCallback']);
        $dispatch->addListener(CompileEvents::POST_COMPILE_LIST, [PhpSubscriber::class, 'applyDefaultCallback']);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // NOTE: This method is only valid on composer v2.
        $dispatch = $composer->getEventDispatcher();
        $dispatch->removeListener(CompileEvents::POST_COMPILE_LIST, [ShellSubscriber::class, 'applyDefaultCallback']);
        $dispatch->removeListener(CompileEvents::POST_COMPILE_LIST, [PhpSubscriber::class, 'applyDefaultCallback']);
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // NOTE: This method is only valid on composer v2.
    }

    /**
     * The "prompt" compilation mode only makes sense with interactive usage.
     */
    public function validateMode(Event $event)
    {
        if (!class_exists('Civi\CompilePlugin\TaskRunner')) {
            // Likely a problem in composer v1 uninstall process?
            return;
        }
        $taskRunner = new TaskRunner($this->composer, $this->io);
        if ($taskRunner->getMode() === 'prompt' && !$this->io->isInteractive()) {
            $this->io->write(file_get_contents(__DIR__ . '/messages/cannot-prompt.txt'));
        }
    }

    public function runTasks(Event $event)
    {
        if (!class_exists('Civi\CompilePlugin\TaskList')) {
            // Likely a problem in composer v1 uninstall process?
            $event->getIO()->write("<warning>Skip CompilePlugin::runTasks. Environment does not appear well-formed.</warning>");
            return;
        }
        $taskList = new TaskList($this->composer, $this->io);
        $taskList->load()->validateAll();

        $taskRunner = new TaskRunner($this->composer, $this->io);

        if (empty($taskList->getAll())) {
            return;
        }

        $this->io->write("<info>Running compilation tasks</info>");
        $taskRunner->runDefault($taskList);
    }
}
