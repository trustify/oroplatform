<?php

use JMS\JobQueueBundle\Entity\Job;

use Oro\Bundle\WorkflowBundle\Configuration\ProcessConfigurationProvider;
use Oro\Bundle\WorkflowBundle\Entity\ProcessTrigger;

return array(
    ProcessConfigurationProvider::NODE_DEFINITIONS => array(
        'test_definition' => array(
            'label'   => 'Test Definition',
            'enabled' => true,
            'entity'  => 'Oro\Bundle\UserBundle\Entity\User',
            'order'   => 20,
            'exclude_definitions'   => [],
            'actions_configuration' => array(
                array('@assign_value' => array('$entity.field', 'value'))
            ),
            'pre_conditions' => array()
        ),
        'another_definition' => array(
            'label'                 => 'Another definition',
            'entity'                => 'My\Entity',
            'actions_configuration' => array(),
            'enabled'               => true,
            'order'                 => 0,
            'exclude_definitions'   => array(),
            'pre_conditions' => array()
        )
    ),
    ProcessConfigurationProvider::NODE_TRIGGERS => array(
        'test_definition' => array(
            array(
                'event'      => ProcessTrigger::EVENT_UPDATE,
                'field'      => 'some_field',
                'priority'   => 10,
                'queued'     => true,
                'time_shift' => 123456,
                'cron'       => null
            ),
            array(
                'event'      => ProcessTrigger::EVENT_CREATE,
                'queued'     => true,
                'time_shift' => 86700,
                'field'      => null,
                'priority'   => Job::PRIORITY_DEFAULT,
                'cron'       => null
            ),
            array(
                'event'      => ProcessTrigger::EVENT_DELETE,
                'field'      => null,
                'priority'   => Job::PRIORITY_DEFAULT,
                'queued'     => false,
                'time_shift' => null,
                'cron'       => null
            ),
            array(
                'event'      => null,
                'field'      => null,
                'priority'   => Job::PRIORITY_DEFAULT,
                'queued'     => false,
                'time_shift' => null,
                'cron'       => '*/1 * * * *'
            )
        )
    )
);
