<?php

/**
 * @file
 * Contains \Drupal\Console\Command\Module\UninstallCommand.
 */

namespace Drupal\Console\Command\Module;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Command\ProjectDownloadTrait;
use Drupal\Console\Style\DrupalStyle;
use Drupal\Console\Command\PHPProcessTrait;

class UninstallCommand extends Command
{
    use PHPProcessTrait;
    use ProjectDownloadTrait;
    use ContainerAwareCommandTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('module:uninstall')
            ->setDescription($this->trans('commands.module.uninstall.description'))
            ->addArgument(
                'module',
                InputArgument::IS_ARRAY,
                $this->trans('commands.module.uninstall.questions.module')
            )
            ->addOption(
                'force',
                '',
                InputOption::VALUE_NONE,
                $this->trans('commands.module.uninstall.options.force')
            )
            ->addOption(
                'composer',
                '',
                InputOption::VALUE_NONE,
                $this->trans('commands.module.uninstall.options.composer')
            );
    }
    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $io = new DrupalStyle($input, $output);
        $module = $input->getArgument('module');
        $composer = $input->getOption('composer');
        $modules = $this->getApplication()->getSite()->getModules(true, true, false, true, true, true);

        if (!$module) {
            $module = $this->modulesUninstallQuestion($io);
            $input->setArgument('module', $module);
        }
    }
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io =  new DrupalStyle($input, $output);
        $composer = $input->getOption('composer');

        $this->get('site')->loadLegacyFile('/core/modules/system/system.module');

        $extension_config = $this->getDrupalService('config.factory')->getEditable('core.extension');

        $moduleInstaller = $this->getDrupalService('module_installer');

        // Get info about modules available
        $module_data = system_rebuild_module_data();

        $module = $input->getArgument('module');

        $module_list = array_combine($module, $module);

        if ($composer) {
            //@TODO: check with Composer if the module is previously required in composer.json!
            foreach ($module as $m) {
                $cmd = "cd " . $this->getApplication()->getSite()->getSiteRoot() . "; ";
                $cmd .= 'composer remove "drupal/' . $m . '"';

                if ($this->execProcess($cmd)) {
                    $io->success(
                        sprintf(
                            $this->trans('commands.module.uninstall.messages.success'),
                            $m
                        )
                    );
                }
            }
            return;
        }

        // Determine if some module request is missing
        if ($missing_modules = array_diff_key($module_list, $module_data)) {
            $io->error(
                sprintf(
                    $this->trans('commands.module.uninstall.messages.missing'),
                    implode(', ', $modules),
                    implode(', ', $missing_modules)
                )
            );

            return true;
        }

        // Only process currently installed modules.
        $installed_modules = $extension_config->get('module') ?: array();
        if (!$module_list = array_intersect_key($module_list, $installed_modules)) {
            $io->info($this->trans('commands.module.uninstall.messages.nothing'));

            return true;
        }

        $force = $input->getOption('force');

        if (!$force) {
            // Calculate $dependents
            $dependents = array();
            while (list($module) = each($module_list)) {
                foreach (array_keys($module_data[$module]->required_by) as $dependent) {
                    // Skip already uninstalled modules.
                    if (isset($installed_modules[$dependent]) && !isset($module_list[$dependent]) && $dependent != $profile) {
                        $dependents[] = $dependent;
                    }
                }
            }

            // Error if there are missing dependencies
            if (!empty($dependents)) {
                $io->error(
                    sprintf(
                        $this->trans('commands.module.uninstall.messages.dependents'),
                        implode(', ', $modules),
                        implode(', ', $dependents)
                    )
                );

                return true;
            }
        }

        // Installing modules
        try {
            // Uninstall the modules.
            $moduleInstaller->uninstall($module_list);

            $io->info(
                sprintf(
                    $this->trans('commands.module.uninstall.messages.success'),
                    implode(', ', $module_list)
                )
            );
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return;
        }
        // Run cache rebuild to see changes in Web UI
        $this->get('chain_queue')->addCommand('cache:rebuild', ['cache' => 'discovery']);
    }
}
