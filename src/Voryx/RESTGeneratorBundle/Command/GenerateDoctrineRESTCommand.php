<?php

/*
 */

namespace Voryx\RESTGeneratorBundle\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Sensio\Bundle\GeneratorBundle\Command\Helper\DialogHelper;
use Voryx\RESTGeneratorBundle\Generator\DoctrineRESTGenerator;
use Sensio\Bundle\GeneratorBundle\Generator\DoctrineFormGenerator;
use Sensio\Bundle\GeneratorBundle\Manipulator\RoutingManipulator;

use Sensio\Bundle\GeneratorBundle\Command\Validators;

/**
 * Generates a REST api for a Doctrine entity.
 *
 */
class GenerateDoctrineRESTCommand extends GenerateDoctrineCommand
{
    private $formGenerator;

    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setDefinition(
                array(
                    new InputOption(
                        'entity',
                        '',
                        InputOption::VALUE_REQUIRED,
                        'The entity class name to initialize (shortcut notation)'
                    ),
                    new InputOption('route-prefix', '', InputOption::VALUE_REQUIRED, 'The route prefix'),
                    new InputOption(
                        'overwrite',
                        '',
                        InputOption::VALUE_NONE,
                        'Do not stop the generation if rest api controller already exist, thus overwriting all generated files'
                    ),
                    new InputOption(
                        'resource',
                        '',
                        InputOption::VALUE_NONE,
                        'The object will return with the resource name'
                    ),
                    new InputOption(
                        'document',
                        '',
                        InputOption::VALUE_NONE,
                        'Use NelmioApiDocBundle to document the controller'
                    ),
                )
            )
            ->setDescription('Generates a REST api based on a Doctrine entity')
            ->setHelp(
                <<<EOT
                The <info>voryx:generate:rest</info> command generates a REST api based on a Doctrine entity.

<info>php app/console voryx:generate:rest --entity=AcmeBlogBundle:Post --route-prefix=post_admin</info>

Every generated file is based on a template. There are default templates but they can be overriden by placing custom templates in one of the following locations, by order of priority:

<info>BUNDLE_PATH/Resources/SensioGeneratorBundle/skeleton/rest
APP_PATH/Resources/SensioGeneratorBundle/skeleton/rest</info>

And

<info>__bundle_path__/Resources/SensioGeneratorBundle/skeleton/form
__project_root__/app/Resources/SensioGeneratorBundle/skeleton/form</info>

You can check https://github.com/sensio/SensioGeneratorBundle/tree/master/Resources/skeleton
in order to know the file structure of the skeleton
EOT
            )
            ->setName('voryx:generate:rest')
            ->setAliases(array('generate:voryx:rest'));
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();

        if ($input->isInteractive()) {
            if (!$dialog->askConfirmation(
                $output,
                $dialog->getQuestion('Do you confirm generation', 'yes', '?'),
                true
            )
            ) {
                $output->writeln('<error>Command aborted</error>');

                return 1;
            }
        }

        $entity = Validators::validateEntityName($input->getOption('entity'));
        list($bundle, $entity) = $this->parseShortcutNotation($entity);

        $prefix = $this->getRoutePrefix($input, $entity);
        $forceOverwrite = $input->getOption('overwrite');

        $dialog->writeSection($output, 'REST api generation');

        $entityClass = $this->getContainer()->get('doctrine')->getAliasNamespace($bundle) . '\\' . $entity;
        $metadata = $this->getEntityMetadata($entityClass);
        $bundle = $this->getContainer()->get('kernel')->getBundle($bundle);
        $resource = $input->getOption('resource');
        $document = $input->getOption('document');

        $generator = $this->getGenerator($bundle);
        $generator->generate($bundle, $entity, $metadata[0], $prefix, $forceOverwrite, $resource, $document);

        $output->writeln('Generating the REST api code: <info>OK</info>');

        $errors = array();
        $runner = $dialog->getRunner($output, $errors);

        // form
        $this->generateForm($bundle, $entity, $metadata);
        $output->writeln('Generating the Form code: <info>OK</info>');

        // TODO: create routing automatically
        if (1 == 2) {
            $runner($this->updateRouting($dialog, $input, $output, $bundle, $entity, $prefix));
        }

        $dialog->writeGeneratorSummary($output, $errors);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();
        $dialog->writeSection($output, 'Welcome to the Doctrine2 REST api generator');

        // namespace
        $output->writeln(
            array(
                '',
                'This command helps you generate a REST api controller.',
                '',
                'First, you need to give the entity for which you want to generate a REST api.',
                'You can give an entity that does not exist yet and the wizard will help',
                'you defining it.',
                '',
                'You must use the shortcut notation like <comment>AcmeBlogBundle:Post</comment>.',
                '',
            )
        );

        $entity = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('The Entity shortcut name', $input->getOption('entity')),
            array('Sensio\Bundle\GeneratorBundle\Command\Validators', 'validateEntityName'),
            false,
            $input->getOption('entity')
        );
        $input->setOption('entity', $entity);
        list($bundle, $entity) = $this->parseShortcutNotation($entity);

        // route prefix
        $prefix = $this->getRoutePrefix($input, $entity);
        $output->writeln(
            array(
                '',
                'Determine the routes prefix (all the routes will be "mounted" under this',
                'prefix: /prefix/, /prefix/new, ...).',
                '',
            )
        );
        $prefix = $dialog->ask($output, $dialog->getQuestion('Routes prefix', '/' . $prefix), '/' . $prefix);
        $input->setOption('route-prefix', $prefix);

        // summary
        $output->writeln(
            array(
                '',
                $this->getHelper('formatter')->formatBlock('Summary before generation', 'bg=blue;fg=white', true),
                '',
                sprintf("You are going to generate a REST api controller for \"<info>%s:%s</info>\"", $bundle, $entity),
                '',
            )
        );
    }

    /**
     * Tries to generate forms if they don't exist yet and if we need write operations on entities.
     */
    protected function generateForm($bundle, $entity, $metadata)
    {
        try {
            $this->getFormGenerator($bundle)->generate($bundle, $entity, $metadata[0]);
        } catch (\RuntimeException $e) {
            // form already exists
        }
    }

    protected function updateRouting(
        DialogHelper $dialog,
        InputInterface $input,
        OutputInterface $output,
        BundleInterface $bundle,
        $entity,
        $prefix
    ) {
        $auto = true;
        if ($input->isInteractive()) {
            $auto = $dialog->askConfirmation(
                $output,
                $dialog->getQuestion('Confirm automatic update of the Routing', 'yes', '?'),
                true
            );
        }

        $output->write('Importing the REST api routes: ');
        $this->getContainer()->get('filesystem')->mkdir($bundle->getPath() . '/Resources/config/');
        $routing = new RoutingManipulator($bundle->getPath() . '/Resources/config/routing.yml');
        try {
            // TODO: fix the format parameter - leaving it for now
            $format = "annotation";
            $ret = $auto ? $routing->addResource(
                $bundle->getName(),
                $format,
                '/' . $prefix,
                'routing/' . strtolower(str_replace('\\', '_', $entity))
            ) : false;
        } catch (\RuntimeException $exc) {
            $ret = false;
        }

        if (!$ret) {
            $help = sprintf(
                "        <comment>resource: \"@%s/Resources/config/routing/%s.%s\"</comment>\n",
                $bundle->getName(),
                strtolower(str_replace('\\', '_', $entity)),
                $format
            );
            $help .= sprintf("        <comment>prefix:   /%s</comment>\n", $prefix);

            return array(
                '- Import the bundle\'s routing resource in the bundle routing file',
                sprintf('  (%s).', $bundle->getPath() . '/Resources/config/routing.yml'),
                '',
                sprintf(
                    '    <comment>%s:</comment>',
                    $bundle->getName() . ('' !== $prefix ? '_' . str_replace('/', '_', $prefix) : '')
                ),
                $help,
                '',
            );
        }
    }

    protected function getRoutePrefix(InputInterface $input, $entity)
    {
        $prefix = $input->getOption('route-prefix') ?: strtolower(str_replace(array('\\', '/'), '_', $entity));

        if ($prefix && '/' === $prefix[0]) {
            $prefix = substr($prefix, 1);
        }

        return $prefix;
    }

    protected function createGenerator($bundle = null)
    {
        return new DoctrineRESTGenerator($this->getContainer()->get('filesystem'));
    }

    protected function getFormGenerator($bundle = null)
    {
        if (null === $this->formGenerator) {
            $this->formGenerator = new DoctrineFormGenerator($this->getContainer()->get('filesystem'));
            $this->formGenerator->setSkeletonDirs($this->getSkeletonDirs($bundle));
        }

        return $this->formGenerator;
    }

    public function setFormGenerator(DoctrineFormGenerator $formGenerator)
    {
        $this->formGenerator = $formGenerator;
    }
}
