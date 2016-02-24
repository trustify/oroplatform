<?php

namespace Oro\Bundle\ApiBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use Oro\Component\ChainProcessor\ChainApplicableChecker;
use Oro\Component\ChainProcessor\Context;
use Oro\Component\ChainProcessor\ProcessorBagInterface;

class DebugCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('oro:api:debug')
            ->setDescription('Shows details about registered API actions and processors.')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Shows a list of processors for a specified action in the order they are executed'
            )
            ->addOption(
                'request-type',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'API request type'
            );
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        if (empty($action)) {
            $this->dumpActions($output);
        } else {
            $this->dumpProcessors($output, $action, $input->getOption('request-type'));
        }
    }

    /**
     * @param OutputInterface $output
     */
    protected function dumpActions(OutputInterface $output)
    {
        /** @var ProcessorBagInterface $processorBag */
        $processorBag = $this->getContainer()->get('oro_api.processor_bag');

        $table = new Table($output);
        $table->setHeaders(['Action', 'Groups']);

        $i = 0;
        foreach ($processorBag->getActions() as $action) {
            if ($i > 0) {
                $table->addRow(new TableSeparator());
            }
            $table->addRow([$action, implode(PHP_EOL, $processorBag->getActionGroups($action))]);
            $i++;
        }

        $table->render();

        $output->writeln('');
        $output->writeln(
            sprintf(
                'To run show a list of processors for some action run <info>%s ACTION</info>',
                $this->getName()
            )
        );
    }

    /**
     * @param OutputInterface $output
     * @param string          $action
     * @param string[]        $requestType
     */
    protected function dumpProcessors(OutputInterface $output, $action, array $requestType)
    {
        $output->writeln('The processors are displayed in order they are executed.');

        /** @var ProcessorBagInterface $processorBag */
        $processorBag = $this->getContainer()->get('oro_api.processor_bag');

        $table = new Table($output);
        $table->setHeaders(['Processor', 'Attributes']);

        $context = new Context();
        $context->setAction($action);
        if (!empty($requestType)) {
            $context->set('requestType', $requestType);
        }
        $processors = $processorBag->getProcessors($context);

        $applicableChecker = new ChainApplicableChecker();
        $applicableChecker->addChecker(new RequestTypeApplicableChecker());
        $processors->setApplicableChecker($applicableChecker);

        $i = 0;
        foreach ($processors as $processor) {
            if ($i > 0) {
                $table->addRow(new TableSeparator());
            }

            $processorColumn      = sprintf(
                '<comment>%s</comment>%s%s',
                $processors->getProcessorId(),
                PHP_EOL,
                get_class($processor)
            );
            $processorDescription = $this->getClassDocComment(get_class($processor));
            if (!empty($processorDescription)) {
                $processorColumn .= PHP_EOL . $processorDescription;
            }

            $attributesColumn = $this->formatProcessorAttributes($processors->getProcessorAttributes());

            $table->addRow(
                [
                    $processorColumn,
                    $attributesColumn
                ]
            );
            $i++;
        }

        $table->render();
    }

    /**
     * @param string $className
     *
     * @return string
     */
    protected function getClassDocComment($className)
    {
        $reflection = new \ReflectionClass($className);

        $comment = $reflection->getDocComment();

        $comment = preg_replace('/^\s+\* @[\w0-9]+.*/msi', '', $comment);
        $comment = strtr($comment, ['/**' => '', '*/' => '']);
        $comment = preg_replace('/^\s+\* ?/m', '', $comment);

        return trim($comment);
    }

    /**
     * @param array $attributes
     *
     * @return string
     */
    protected function formatProcessorAttributes(array $attributes)
    {
        $rows = [];

        if (array_key_exists('group', $attributes)) {
            $rows[] = implode(
                ': ',
                [
                    'group',
                    sprintf('<comment>%s</comment>', $this->formatProcessorAttributeValue($attributes['group']))
                ]
            );
            unset($attributes['group']);
        }

        foreach ($attributes as $key => $val) {
            $rows[] = implode(': ', [$key, $this->formatProcessorAttributeValue($val)]);
        }

        return implode(PHP_EOL, $rows);
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    protected function formatProcessorAttributeValue($value)
    {
        if (is_array($value)) {
            return '[' . implode(', ', $value) . ']';
        }

        return (string)$value;
    }
}
