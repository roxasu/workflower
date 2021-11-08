<?php
/*
 * Copyright (c) Atsuhiro Kubo <kubo@iteman.jp> and contributors,
 * All rights reserved.
 *
 * This file is part of Workflower.
 *
 * This program and the accompanying materials are made available under
 * the terms of the BSD 2-Clause License which accompanies this
 * distribution, and is available at http://opensource.org/licenses/BSD-2-Clause
 */

namespace PHPMentors\Workflower\Workflow;

use PHPMentors\Workflower\Workflow\Activity\ActivityInterface;
use PHPMentors\Workflower\Workflow\Activity\CallTask;
use PHPMentors\Workflower\Workflow\Activity\SubProcessTask;
use PHPMentors\Workflower\Workflow\Activity\UnexpectedActivityException;
use PHPMentors\Workflower\Workflow\Activity\WorkItem;
use PHPMentors\Workflower\Workflow\Activity\WorkItemInterface;
use PHPMentors\Workflower\Workflow\Element\ConnectingObjectCollection;
use PHPMentors\Workflower\Workflow\Element\ConnectingObjectInterface;
use PHPMentors\Workflower\Workflow\Element\FlowObject;
use PHPMentors\Workflower\Workflow\Element\FlowObjectCollection;
use PHPMentors\Workflower\Workflow\Element\FlowObjectInterface;
use PHPMentors\Workflower\Workflow\Element\Token;
use PHPMentors\Workflower\Workflow\Element\TransitionalInterface;
use PHPMentors\Workflower\Workflow\Event\EndEvent;
use PHPMentors\Workflower\Workflow\Event\StartEvent;
use PHPMentors\Workflower\Workflow\Event\TerminateEndEvent;
use PHPMentors\Workflower\Workflow\Operation\OperationRunnerInterface;
use PHPMentors\Workflower\Workflow\Participant\ParticipantInterface;
use PHPMentors\Workflower\Workflow\Participant\Role;
use PHPMentors\Workflower\Workflow\Participant\RoleCollection;
use PHPMentors\Workflower\Workflow\Provider\DataProviderInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class ProcessInstance implements ProcessInstanceInterface, \Serializable
{
    const DEFAULT_ROLE_ID = '__ROLE__';

    /**
     * @var int|string
     *
     * @since Property available since Release 2.0.0
     */
    private $id;

    /**
     * @var ProcessInstanceInterface
     */
    private $parentProcessInstance;

    /**
     * @var ActivityInterface
     */
    private $parentActivity;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $state = self::STATE_STARTED;

    /**
     * @var ConnectingObjectCollection
     */
    private $connectingObjectCollection;

    /**
     * @var FlowObjectCollection
     */
    private $flowObjectCollection;

    /**
     * @var RoleCollection
     */
    private $roleCollection;

    /**
     * @var StartEvent
     *
     * @since Property available since Release 2.0.0
     */
    private $startEvent;

    /**
     * @var EndEvent[]
     *
     * @since Property available since Release 2.0.0
     */
    private $endEvents = [];

    /**
     * @var \DateTime
     *
     * @since Property available since Release 2.0.0
     */
    private $endDate;

    /**
     * @var array
     */
    private $processData;

    /**
     * @var ExpressionLanguage
     *
     * @since Property available since Release 1.1.0
     */
    private $expressionLanguage;

    /**
     * @var OperationRunnerInterface
     *
     * @since Property available since Release 1.2.0
     */
    private $operationRunner;

    /**
     * @var DataProviderInterface
     *
     * @since Property available since Release 2.0.0
     */
    private $dataProvider;

    /**
     * @var Token[]
     *
     * @since Property available since Release 2.0.0
     */
    private $tokens = [];

    /**
     * @var ActivityLogCollection
     *
     * @since Property available since Release 2.0.0
     */
    private $activityLogCollection;

    /**
     * @var ProcessDefinitionInterface
     */
    private $processDefinition;

    /**
     * @param int|string $id
     * @param string $name
     */
    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->connectingObjectCollection = new ConnectingObjectCollection();
        $this->flowObjectCollection = new FlowObjectCollection();
        $this->roleCollection = new RoleCollection();
        $this->activityLogCollection = new ActivityLogCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function serialize()
    {
        return serialize([
            'id' => $this->id,
            'parentProcessInstance' => $this->parentProcessInstance,
            'parentActivity' => $this->parentActivity,
            'state' => $this->state,
            'name' => $this->name,
            'endDate' => $this->endDate,
            'connectingObjectCollection' => $this->connectingObjectCollection,
            'flowObjectCollection' => $this->flowObjectCollection,
            'roleCollection' => $this->roleCollection,
            'startEvent' => $this->startEvent->getId(),
            'endEvents' => collect($this->endEvents)->map(function (EndEvent $item) {
                return $item->getId();
            })->toArray(),
            'tokens' => collect($this->tokens)->map(function (Token $token) {
                return [
                    'id' => $token->getId(),
                    'currentFlowObject' => $token->getCurrentFlowObject()->getId(),
                    'previousFlowObject' => $token->getPreviousFlowObject()->getId(),
                ];
            })->toArray(),
            'activityLogCollection' => collect($this->activityLogCollection)->map(function (ActivityLog $log) {
                return [
                    'activity' => $log->getActivity()->getId(),
                    'workItem' => $log->getWorkItem()->getId(),
                ];
            })->toArray(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized)
    {
        foreach (unserialize($serialized) as $name => $value) {
            if (property_exists($this, $name)) {
                $this->$name = $value;
            }
        }

        $this->setFields();
    }

    /**
     * @author yame
     */
    protected function setFields()
    {
        //设置角色
        $roleCollection = new RoleCollection();
        foreach ($this->roleCollection as $role) {
            $roleCollection->add(new Role($role));
        }
        $this->roleCollection = $roleCollection;

        $workItems = [];
        foreach ($this->flowObjectCollection as $item) {
            if (method_exists($item, 'getWorkItems')) {
                foreach ($item->getWorkItems() as $workItem) {
                    $workItem->setProcessInstance($this);
                    $workItem->setActivity($item);

                    $workItems[] = $workItem;
                }
            }

            if ($role = $item->getRole()) {
                $item->setRole($this->roleCollection->get($role));
            }

            $item->setProcessInstance($this);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return int|string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function setProcessInstance(ProcessInstanceInterface $processInstance)
    {
        $this->parentProcessInstance = $processInstance;
    }

    /**
     * {@inheritdoc}
     */
    public function getProcessInstance()
    {
        return $this->parentProcessInstance;
    }

    /**
     * {@inheritdoc}
     */
    public function setActivity(ActivityInterface $activity)
    {
        $this->parentActivity = $activity;
    }

    /**
     * {@inheritdoc}
     */
    public function getActivity()
    {
        return $this->parentActivity;
    }

    /**
     * {@inheritdoc}
     */
    public function getState()
    {
        return $this->state;
    }

    public function setProcessDefinition(ProcessDefinitionInterface $definition)
    {
        $this->processDefinition = $definition;
    }

    public function getProcessDefinition()
    {
        return $this->processDefinition;
    }

    /**
     * @param ConnectingObjectInterface $connectingObject
     */
    public function addConnectingObject(ConnectingObjectInterface $connectingObject)
    {
        $this->connectingObjectCollection->add($connectingObject);
    }

    /**
     * @param FlowObjectInterface $flowObject
     */
    public function addFlowObject(FlowObjectInterface $flowObject)
    {
        $flowObject->setProcessInstance($this);
        $this->flowObjectCollection->add($flowObject);
    }

    /**
     * @param int|string $id
     *
     * @return ConnectingObjectInterface|null
     */
    public function getConnectingObject($id)
    {
        return $this->connectingObjectCollection->get($id);
    }

    /**
     * @param TransitionalInterface $flowObject
     *
     * @return ConnectingObjectCollection
     */
    public function getConnectingObjectCollectionBySource(TransitionalInterface $flowObject)
    {
        return $this->connectingObjectCollection->filterBySource($flowObject);
    }

    /**
     * @param TransitionalInterface $flowObject
     *
     * @return ConnectingObjectCollection
     */
    public function getConnectingObjectCollectionByDestination(TransitionalInterface $flowObject)
    {
        return $this->connectingObjectCollection->filterByDestination($flowObject);
    }

    /**
     * @param int|string $id
     *
     * @return FlowObjectInterface|null
     */
    public function getFlowObject($id)
    {
        return $this->flowObjectCollection->get($id);
    }

    /**
     * @param Role $role
     */
    public function addRole(Role $role)
    {
        $this->roleCollection->add($role);
    }

    /**
     * @param int|string $id
     *
     * @return bool
     */
    public function hasRole($id)
    {
        return $this->roleCollection->get($id) !== null;
    }

    /**
     * @param int|string $id
     *
     * @return Role
     */
    public function getRole($id)
    {
        return $this->roleCollection->get($id);
    }

    /**
     * {@inheritdoc}
     */
    public function isActive()
    {
        return count($this->tokens) > 0 && isset($this->startEvent);
    }

    /**
     * {@inheritdoc}
     */
    public function isEnded()
    {
        $state = $this->state;

        return count($this->tokens) == 0 &&
            ($state === self::STATE_ENDED || $state === self::STATE_CANCELLED || $state === self::STATE_ABNORMAL);
    }

    /**
     * @return FlowObjectInterface|null
     */
    public function getCurrentFlowObject(): ?FlowObjectInterface
    {
        $flowObjects = $this->getCurrentFlowObjects();
        if (count($flowObjects) == 0) {
            return null;
        }

        return $flowObjects[0];
    }

    /**
     * @return FlowObjectInterface[]
     *
     * @since Method available since Release 2.0.0
     */
    public function getCurrentFlowObjects(): iterable
    {
        return array_map(function (Token $token) {
            return $token->getCurrentFlowObject();
        }, $this->tokens
        );
    }

    /**
     * @return FlowObjectInterface|null
     */
    public function getPreviousFlowObject(): ?FlowObjectInterface
    {
        $flowObjects = $this->getPreviousFlowObjects();
        if (count($flowObjects) == 0) {
            return null;
        }

        return $flowObjects[0];
    }

    /**
     * @return FlowObjectInterface[]
     *
     * @since Method available since Release 2.0.0
     */
    public function getPreviousFlowObjects(): iterable
    {
        return array_map(function (Token $token) {
            return $token->getPreviousFlowObject();
        }, $this->tokens
        );
    }

    /**
     * {@inheritdoc}
     */
    public function start(StartEvent $event)
    {
        $this->startEvent = $event;
        $event->run($this->generateToken($this->startEvent));
    }

    /**
     * @param WorkItemInterface $workItem
     * @param ParticipantInterface $participant
     */
    public function allocateWorkItem(WorkItemInterface $workItem, ParticipantInterface $participant)
    {
        $activity = $workItem->getActivity();
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);

        $workItem->allocate($participant);
    }

    /**
     * @param WorkItemInterface $workItem
     * @param ParticipantInterface $participant
     */
    public function startWorkItem(WorkItemInterface $workItem, ParticipantInterface $participant)
    {
        $activity = $workItem->getActivity();
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);

        $workItem->start();
    }

    /**
     * @param WorkItemInterface $workItem
     * @param ParticipantInterface $participant
     */
    public function completeWorkItem(WorkItemInterface $workItem, ParticipantInterface $participant)
    {
        $activity = $workItem->getActivity();
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);

        $workItem->complete($participant);
    }

    /**
     * @param array $processData
     */
    public function setProcessData(array $processData)
    {
        $this->processData = $processData;
    }

    /**
     * @return array
     *
     * @since Method available since Release 1.2.0
     */
    public function getProcessData()
    {
        return $this->processData;
    }

    /**
     * @param ExpressionLanguage $expressionLanguage
     *
     * @since Method available since Release 1.1.0
     */
    public function setExpressionLanguage(ExpressionLanguage $expressionLanguage)
    {
        $this->expressionLanguage = $expressionLanguage;
    }

    /**
     * @return ExpressionLanguage
     */
    public function getExpressionLanguage()
    {
        return $this->expressionLanguage;
    }

    /**
     * @param OperationRunnerInterface $operationRunner
     *
     * @since Method available since Release 1.2.0
     */
    public function setOperationRunner(OperationRunnerInterface $operationRunner)
    {
        $this->operationRunner = $operationRunner;
    }

    /**
     * @return OperationRunnerInterface
     */
    public function getOperationRunner(): OperationRunnerInterface
    {
        return $this->operationRunner;
    }

    /**
     * @param DataProviderInterface $dataProvider
     */
    public function setDataProvider(DataProviderInterface $dataProvider): void
    {
        $this->dataProvider = $dataProvider;
    }

    /**
     * @return DataProviderInterface
     */
    public function getDataProvider()
    {
        return $this->dataProvider;
    }

    /**
     * @param EndEvent $event
     */
    public function end(EndEvent $event)
    {
        $this->endEvents[] = $event;

        $token = current($event->getToken());
        $this->removeToken($event, $token);

        // when it's a Terminate End Event cancel all other tokens available
        // otherwise wait for all other tokens to arrive in End Events to end
        // the process instance

        if ($event instanceof TerminateEndEvent) {
            $this->cancel();
            $this->state = self::STATE_ABNORMAL;
        } elseif (count($this->tokens) == 0) {
            $this->endDate = $event->getEndDate();
            $this->state = self::STATE_ENDED;

            $parentActivity = $this->getActivity();

            if ($parentActivity !== null) {
                $parentActivity->completeWork();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancel()
    {
        if (!$this->isEnded()) {
            // cancel the process instance and change the state
            foreach ($this->getCurrentFlowObjects() as $flowObject) {
                $flowObject->cancel();
            }

            $this->state = self::STATE_CANCELLED;

            $parentActivity = $this->getActivity();

            if ($parentActivity !== null) {
                $parentActivity->completeWork();
            }
        }
    }

    /**
     * @return \DateTime
     */
    public function getStartDate()
    {
        if ($this->startEvent === null) {
            return null;
        }

        return $this->startEvent->getStartDate();
    }

    /**
     * @return \DateTime
     */
    public function getEndDate()
    {
        return $this->endDate;
    }

    /**
     * @return ActivityLogCollection
     */
    public function getActivityLog()
    {
        return $this->activityLogCollection;
    }

    /**
     * @param ActivityInterface $activity
     * @param ParticipantInterface $participant
     *
     * @throws AccessDeniedException
     */
    private function assertParticipantHasRole(ActivityInterface $activity, ParticipantInterface $participant)
    {
        if (!$participant->hasRole($activity->getRole()->getId())) {
            throw new AccessDeniedException(sprintf('The participant "%s" does not have the role "%s" that is required to operate the activity "%s".', $participant->getId(), $activity->getRole()->getId(), $activity->getId()));
        }
    }

    /**
     * @param ActivityInterface $activity
     *
     * @throws UnexpectedActivityException
     */
    private function assertCurrentFlowObjectIsExpectedActivity(ActivityInterface $activity)
    {
        foreach ($this->getCurrentFlowObjects() as $currentFlowObject) {
            if ($activity->equals($currentFlowObject)) {
                return true;
            }
        }

        throw new UnexpectedActivityException(sprintf('The current flow object is not equal to the expected activity "%s".', $activity->getId()));
    }

    private function generateId()
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param FlowObjectInterface $flowObject
     *
     * @return Token
     *
     * @since Method available since Release 2.0.0
     */
    public function generateToken(FlowObjectInterface $flowObject): Token
    {
        $token = new Token($this->generateId(), $flowObject);
        $this->tokens[] = $token;

        return $token;
    }

    /**
     * @param ActivityInterface $activity
     *
     * @return WorkItemInterface
     *
     * @since Method available since Release 2.0.0
     */
    public function generateWorkItem(ActivityInterface $activity): WorkItemInterface
    {
        $workItem = new WorkItem($this->generateId());
        $workItem->setProcessInstance($this);
        $workItem->setActivity($activity);
        $this->getActivityLog()->add(new ActivityLog($workItem));

        return $workItem;
    }

    /**
     * @param ActivityInterface $activity
     *
     * @return ItemsCollectionInterface
     *
     * @since Method available since Release 2.0.0
     */
    public function generateWorkItemsCollection(ActivityInterface $activity)
    {
        $isSubProcess = ($activity instanceof SubProcessTask || $activity instanceof CallTask);
        $collection = $isSubProcess ? new ProcessInstancesCollection() : new WorkItemsCollection();

        return $collection;
    }

    /**
     * @param FlowObjectInterface $flowObject
     * @param Token $token
     *
     * @since Method available since Release 2.0.0
     */
    public function removeToken(FlowObjectInterface $flowObject, Token $token): void
    {
        $flowObject->detachToken($token);
        $this->tokens = array_filter($this->tokens, function (Token $currentToken) use ($token) {
            return $currentToken !== $token;
        });
    }

    /**
     * @return StartEvent
     */
    public function getFirstStartEvent()
    {
        foreach ($this->flowObjectCollection as $flowObject) {
            if ($flowObject instanceof StartEvent) {
                return $flowObject;
            }
        }

        return null;
    }
}
