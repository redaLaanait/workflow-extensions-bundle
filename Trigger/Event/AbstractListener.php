<?php
/**
 * This file is part of the Global Trading Technologies Ltd workflow-extension-bundle package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * (c) fduch <alex.medwedew@gmail.com>
 * @date 29.06.16
 */

namespace Gtt\Bundle\WorkflowExtensionsBundle\Trigger\Event;

use Gtt\Bundle\WorkflowExtensionsBundle\WorkflowContext;
use Gtt\Bundle\WorkflowExtensionsBundle\Exception\UnsupportedTriggerEventException;
use Gtt\Bundle\WorkflowExtensionsBundle\WorkflowSubject\SubjectManipulator;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Workflow\Registry;
use Psr\Log\LoggerInterface;
use Exception;
use Throwable;

/**
 * Holds base functionality for all workflow event listeners
 */
abstract class AbstractListener
{
    /**
     * Holds listener configurations for events to be dispatched by current listener
     *
     * @var array
     */
    protected $supportedEventsConfig = [];

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Expression language for retrieving subject from event
     *
     * @var ExpressionLanguage
     */
    private $subjectRetrieverLanguage;

    /**
     * Subject manipulator
     *
     * @var SubjectManipulator
     */
    private $subjectManipulator;

    /**
     * Workflow registry
     *
     * @var Registry
     */
    private $workflowRegistry;

    /**
     * AbstractListener constructor.
     *
     * @param ExpressionLanguage $subjectRetrieverLanguage subject retriever expression language
     * @param SubjectManipulator $subjectManipulator       subject manipulator
     * @param Registry           $workflowRegistry         workflow registry
     * @param LoggerInterface    $logger                   logger
     */
    public function __construct(
        ExpressionLanguage $subjectRetrieverLanguage,
        SubjectManipulator $subjectManipulator,
        Registry $workflowRegistry,
        LoggerInterface $logger)
    {
        $this->subjectRetrieverLanguage = $subjectRetrieverLanguage;
        $this->subjectManipulator = $subjectManipulator;
        $this->workflowRegistry   = $workflowRegistry;
        $this->logger             = $logger;
    }

    /**
     * Sets configs for event to be dispatched by current listener
     *
     * @param string $eventName                   event name
     * @param string $workflowName                workflow name
     * @param string $subjectRetrievingExpression expression used to retrieve subject from event
     */
    protected function configureSubjectRetrievingForEvent(
        $eventName,
        $workflowName,
        $subjectRetrievingExpression)
    {
        if (!isset($this->supportedEventsConfig[$eventName])) {
            $this->supportedEventsConfig[$eventName] = [];
        }

        $this->supportedEventsConfig[$eventName][$workflowName] = [
            'subject_retrieving_expression' => $subjectRetrievingExpression
        ];
    }

    /**
     * Dispatches registered event
     *
     * @param Event  $event     event
     * @param string $eventName event name
     */
    final public function dispatchEvent(Event $event, $eventName)
    {
        if (!array_key_exists($eventName, $this->supportedEventsConfig)) {
            throw new UnsupportedTriggerEventException(sprintf("Cannot find registered trigger event by name '%s'", $eventName));
        }

        foreach ($this->supportedEventsConfig[$eventName] as $workflowName => $eventConfigForWorkflow) {
            $subjectRetrievingExpression = $eventConfigForWorkflow['subject_retrieving_expression'];
            $subject = $this->retrieveSubjectFromEvent($event, $eventName, $workflowName, $subjectRetrievingExpression);
            if (!$subject) {
                continue;
            }

            $this->handleEvent(
                $eventName,
                $event,
                $eventConfigForWorkflow,
                $this->getWorkflowContext($subject, $workflowName)
            );
        }
    }

    /**
     * Reacts on the event occurred with some activity
     *
     * @param string          $eventName              event name
     * @param Event           $event                  event instance
     * @param array           $eventConfigForWorkflow registered config for particular event handling
     * @param WorkflowContext $workflowContext        workflow context
     *
     * @return void
     */
    abstract protected function handleEvent($eventName, Event $event, $eventConfigForWorkflow, WorkflowContext $workflowContext);

    /**
     * Allows to execute any listener callback with internal errors and exceptions caught
     * in order to make possible next execution
     *
     * @param \Closure        $closure         closure to be executed safely
     * @param string          $eventName       event name
     * @param WorkflowContext $workflowContext workflow context
     * @param string          $activity        description of the current listener activity (required for logging
     *                                         purposes)
     */
    protected function executeSafely(\Closure $closure, $eventName, WorkflowContext $workflowContext, $activity = 'react')
    {
        try {
            call_user_func($closure);
        } catch (Exception $e) {
            $this->logger->error(
                sprintf('Cannot %s on event "%s". Details: %s', $activity, $eventName, $e->getMessage()),
                $workflowContext->getLoggerContext()
            );
        } catch (Throwable $e) {
            $this->logger->critical(
                sprintf('Cannot %s on event "%s". Details: %s', $activity, $eventName, $e->getMessage()),
                $workflowContext->getLoggerContext()
            );
        }
    }

    /**
     * Retrieves workflow subject from event
     *
     * @param Event  $event                       event to be dispatched
     * @param string $eventName                   event name
     * @param string $workflowName                workflow
     * @param string $subjectRetrievingExpression expression used to retrieve subject from event
     *
     * @return object|null
     */
    private function retrieveSubjectFromEvent(Event $event, $eventName, $workflowName, $subjectRetrievingExpression)
    {
        try {
            $error = false;

            /** @var object|mixed $subject */
            $subject = $this->subjectRetrieverLanguage->evaluate($subjectRetrievingExpression, ['event' => $event]);

            if (!is_object($subject)) {
                $error = sprintf(
                    "Subject retrieving from '%s' event by expression '%s' ended with empty or non-object result",
                    $eventName,
                    $subjectRetrievingExpression
                );
            } else {
                $this->logger->debug(sprintf('Retrieved subject from "%s" event', $eventName), ['workflow' => $workflowName]);

                return $subject;
            }
        } catch (Exception $e) {
            $error = sprintf(
                "Cannot retrieve subject from event '%s' by evaluating expression '%s'. Error: '%s'. Please check retrieving expression",
                $eventName,
                $subjectRetrievingExpression,
                $e->getMessage()
            );
        } catch (Throwable $e) {
            $error = sprintf(
                "Cannot retrieve subject from event '%s' by evaluating expression '%s'. Error: '%s'. Please check retrieving expression",
                $eventName,
                $subjectRetrievingExpression,
                $e->getMessage()
            );
        } finally {
            if ($error) {
                $this->logger->error($error, ['workflow' => $workflowName]);
            }
        }
    }

    /**
     * Creates workflow context
     *
     * @param object $subject      workflow subject
     * @param string $workflowName workflow name
     *
     * @return WorkflowContext
     */
    private function getWorkflowContext($subject, $workflowName)
    {
        $workflowContext = new WorkflowContext(
            $this->workflowRegistry->get($subject, $workflowName),
            $subject,
            $this->subjectManipulator->getSubjectId($subject)
        );

        return $workflowContext;
    }
}