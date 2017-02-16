<?php namespace App\Services;

use App\Repositories\Sequence\SequenceScheduleRepositoryInterface;
use Carbon\Carbon;
use App\Models\Bot;
use App\Models\Subscriber;
use App\Models\AudienceFilter;
use Illuminate\Pagination\Paginator;
use App\Services\Facebook\FacebookUser;
use App\Repositories\Bot\BotRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Repositories\Sequence\SequenceRepositoryInterface;
use App\Repositories\Subscriber\SubscriberRepositoryInterface;
use MongoDB\BSON\ObjectID;

class SubscriberService
{

    /**
     * @type array
     */
    protected $filterFieldsMap
        = [
            'first_name'          => 'first_name',
            'last_name'           => 'last_name',
            'active'              => 'active',
            'gender'              => 'gender',
            'created_at'          => 'created_at',
            'last_interaction_at' => 'last_interaction_at',
        ];

    /**
     * @type FacebookUser
     */
    private $FacebookUsers;
    /**
     * @type SubscriberRepositoryInterface
     */
    private $subscriberRepo;
    /**
     * @type SequenceRepositoryInterface
     */
    private $sequenceRepo;
    /**
     * @type SequenceService
     */
    private $sequences;
    /**
     * @type BotRepositoryInterface
     */
    private $botRepo;
    /**
     * @var SequenceScheduleRepositoryInterface
     */
    private $sequenceScheduleRepo;

    /**
     * AudienceService constructor.
     *
     * @param SubscriberRepositoryInterface       $subscriberRepo
     * @param SequenceRepositoryInterface         $sequenceRepo
     * @param FacebookUser                        $FacebookUsers
     * @param SequenceService                     $sequences
     * @param BotRepositoryInterface              $botRepo
     * @param SequenceScheduleRepositoryInterface $sequenceScheduleRepo
     */
    public function __construct(
        SubscriberRepositoryInterface $subscriberRepo,
        SequenceRepositoryInterface $sequenceRepo,
        FacebookUser $FacebookUsers,
        SequenceService $sequences,
        BotRepositoryInterface $botRepo,
        SequenceScheduleRepositoryInterface $sequenceScheduleRepo
    ) {
        $this->FacebookUsers = $FacebookUsers;
        $this->subscriberRepo = $subscriberRepo;
        $this->sequenceRepo = $sequenceRepo;
        $this->sequences = $sequences;
        $this->botRepo = $botRepo;
        $this->sequenceScheduleRepo = $sequenceScheduleRepo;
    }

    /**
     * @param      $id
     *
     * @return Subscriber
     */
    public function find($id)
    {
        return $this->subscriberRepo->findById($id);
    }

    /**
     * @param      $id
     * @param Bot  $bot
     *
     * @return Subscriber
     */
    public function findForBotOrFail($id, Bot $bot)
    {
        if ($subscriber = $this->subscriberRepo->findByIdForBot($id, $bot)) {
            return $subscriber;
        }
        throw new ModelNotFoundException;
    }

    /**
     * @param      $id
     * @param Bot  $page
     *
     * @return Subscriber|null
     */
    public function findByFacebookId($id, Bot $page)
    {
        return $this->subscriberRepo->findByFacebookIdForBot($id, $page);
    }

    /**
     * Get or create a new subscriber to a given page.
     *
     * @param      $id
     * @param Bot  $bot
     * @param bool $isActive whether or not the user is actually an active subscriber or not.
     *
     * @return Subscriber|null
     */
    public function getByFacebookIdOrCreate($id, Bot $bot, $isActive = false)
    {
        if ($subscriber = $this->findByFacebookId($id, $bot)) {
            return $subscriber;
        }

        $publicProfile = $this->FacebookUsers->publicProfile($id, $bot->page->access_token);

        $data = [
            'facebook_id'          => $id,
            'first_name'           => $publicProfile->first_name,
            'last_name'            => $publicProfile->last_name,
            'avatar_url'           => $publicProfile->profile_pic,
            'locale'               => $publicProfile->locale,
            'timezone'             => $publicProfile->timezone,
            'gender'               => $publicProfile->gender,
            'active'               => $isActive,
            'bot_id'               => $bot->_id,
            'last_subscribed_at'   => $isActive ? Carbon::now() : null,
            'last_unsubscribed_at' => null,
            'tags'                 => [],
            'sequences'            => [],
            'removed_sequences'    => [],
            'history'              => [],
        ];

        /** @type Subscriber $subscriber */
        $subscriber = $this->subscriberRepo->create($data);

        if ($isActive) {
            $this->subscribeToBotSequences($subscriber, $bot);
        }

        return $subscriber;
    }

    /**
     * Make a subscriber "active"
     *
     * @param int $id the subscriber ID
     * @param Bot $bot
     */
    public function resubscribe($id, Bot $bot)
    {
        $subscriber = $this->findByFacebookId($id, $bot);
        $this->subscriberRepo->resubscribe($subscriber);
        $this->subscribeToBotSequences($subscriber, $bot);
    }

    /**
     * Make a subscriber inactive.
     *
     * @param Subscriber $subscriber
     *
     * @return Subscriber
     */
    public function unsubscribe(Subscriber $subscriber)
    {
        return $this->subscriberRepo->unsubscribe($subscriber);
    }

    /**
     * Return a list of filtered and sorted subscribers.
     * Subscribers may be filtered by simple attribute matching,
     * or by more complicated Filter Groups and Filter Rules (using logical and/or).
     *
     * @param Bot   $bot
     * @param int   $page
     * @param array $filterBy
     * @param array $orderBy
     * @param int   $perPage
     *
     * @return Paginator
     */
    public function paginate(Bot $bot, $page = 1, $filterBy = [], $orderBy = [], $perPage = 20)
    {
        return $this->subscriberRepo->paginateForBot(
            $bot,
            $page,
            $this->normalizeFilterBy($filterBy),
            $this->normalizeOrderBy($orderBy),
            $perPage
        );
    }

    /**
     * There are different "matching" techniques: exact matching, prefix, or date string (today, yesterday.. etc).
     * This method loops all over the filterBy array, make sure that the field is filterable, and return an array of filtering conditions.
     * A filtering condition has 3 parts:
     * 1. Type: [a]exact: exact match. [b]prefix: prefix match. [c]date: date lower & upper boundaries.
     * 2. Attribute: name of the attribute.
     * 3. Value: value to be matched against.
     *
     * @param array $filterBy
     *
     * @return array Array of the filtering conditions.
     */
    private function normalizeFilterBy(array $filterBy)
    {
        $ret = [];

        if ($filter = array_get($filterBy, 'filter', [])) {
            $ret[] = [
                'operator' => 'subscriber',
                'filter'   => new AudienceFilter($this->normalizeFilter($filter))
            ];
        }

        foreach ($filterBy as $key => $value) {

            if (! $this->fieldIsFilterable($key) || ($value !== '0' && ! $value)) {
                continue;
            }

            $operator = '=';

            $key = $this->filterFieldsMap[$key];

            if (in_array($key, ['first_name', 'last_name'])) {
                $operator = 'prefix';
            }

            if (in_array($key, ['created_at', 'last_interaction_at'])) {
                $operator = 'date';
            }

            $ret[] = compact('operator', 'key', 'value');
        }

        $this->addActiveFilter($ret);

        return $ret;
    }

    /**
     * Normalize the filter groups by removing empty rules and empty groups.
     *
     * @param $filter
     *
     * @return mixed
     */
    private function normalizeFilter(array $filter)
    {
        if (! $filter) {
            return $filter;
        }

        if (! ($filter['enabled'] = (bool)array_get($filter, 'enabled', false))) {
            return array_only($filter, 'enabled');
        }

        $filter['groups'] = array_get($filter, 'groups', []);

        foreach ($filter['groups'] as $i => $group) {
            $filter['groups'][$i]['rules'] = $this->removeRulesWithoutValues($group['rules']);
            if ($filter['groups'][$i]['rules']) {
                $filter['groups'][$i]['join_type'] = array_get($filter['groups'][$i], 'join_type', 'and');
            }
        }

        // If a group is empty (has no rules), remove it.
        $filter['groups'] = array_filter($filter['groups'], function ($group) {
            return ! empty($group['rules']);
        });

        if ($filter['groups']) {
            $filter['join_type'] = array_get($filter, 'join_type', 'and');
        }

        return $filter;
    }

    /**
     * If a rule has no value, then remove it from the filter groups.
     *
     * @param $rules
     *
     * @return array
     */
    private function removeRulesWithoutValues($rules)
    {
        return array_filter($rules, function ($rule) {
            return ! empty($rule['value']);
        });
    }

    /**
     * @param array $filterBy
     */
    private function addActiveFilter(array &$filterBy)
    {
        $filterBy[] = [
            'operator' => '=',
            'key'      => 'active',
            'value'    => true
        ];
    }

    /**
     * Return an associative array of order fields.
     * Every key is the attribute to be sorted by, and the value is either "asc" / "desc"
     *
     * @param array $orderBy
     *
     * @return array
     */
    private function normalizeOrderBy(array $orderBy)
    {
        $ret = [];
        foreach ($orderBy as $attribute => $order) {
            if ($this->fieldIsFilterable($attribute)) {
                $attribute = $this->filterFieldsMap[$attribute];
                $ret[$attribute] = strtolower($order) == 'desc' ? 'desc' : 'asc';
            }
        }

        return $ret;
    }

    /**
     * Update a subscriber.
     *
     * @param array $input
     * @param int   $subscriberId
     * @param Bot   $bot
     *
     * @return Subscriber
     */
    public function update(array $input, $subscriberId, Bot $bot)
    {
        $subscriber = $this->findForBotOrFail($subscriberId, $bot);

        $this->botRepo->createTagsForBot($bot->_id, $input['tags']);

        $newSequenceIDs = array_map(function ($sequence) {
            return new ObjectID($sequence['id']);
        }, $input['sequences']);

        $sequencesToAdd = array_diff($newSequenceIDs, $subscriber->sequences);
        $sequencesToRemove = array_diff($subscriber->sequences, $newSequenceIDs);

        $data = [
            'tags'              => $input['tags'],
            'sequences'         => $newSequenceIDs,
            'removed_sequences' => array_merge(array_diff($subscriber->removed_sequences, $sequencesToAdd), $sequencesToRemove),
        ];

        $this->subscribeToSequences($subscriber, $sequencesToAdd);

        $this->subscriberRepo->update($subscriber, $data);

        return $subscriber;
    }

    /**
     * @todo handle sequence subscription:
     *      -> one query per sequence
     *      -> For every bot sequence get the list of matching subscribers whose ids
     *         intersect with $subscriberIds, and then subscribe them.
     * Batch update subscribers.
     *
     * @param array $input
     * @param array $subscriberIds
     * @param Bot   $bot
     */
    public function batchUpdate(array $input, array $subscriberIds, Bot $bot)
    {
        if ($subscriberIds) {
            $this->botRepo->createTagsForBot($bot->_id, array_merge($input['add_tags'], $input['remove_tags']));
            $this->subscriberRepo->bulkAddRemoveTagsAndSequences($bot, $subscriberIds, $input);
        }
    }

    /**
     * Return the number of active subscribers for a certain page.
     *
     * @param Bot $bot
     *
     * @return int
     */
    public function activeSubscribers(Bot $bot)
    {
        return $this->subscriberRepo->activeSubscriberCountForBot($bot);
    }

    /**
     * Return the total number of subscription actions in a given period of time.
     * Calculated as the difference between subscription and unsubscription actions.
     *
     * @param Bot           $bot
     * @param Carbon|string $date
     *
     * @return integer
     */
    public function totalSubscriptions(Bot $bot, $date)
    {
        $subscriptions = $this->subscriberRepo->subscriptionCountForBot($bot, $date);
        $unsubscriptions = $this->subscriberRepo->unsubscriptionCountForBot($bot, $date);

        return $subscriptions - $unsubscriptions;
    }

    /**
     * Count the number of subscribers who last unsubscribed in a given time period or on a specific date.
     *
     * @param Bot           $page
     * @param Carbon|string $date
     *
     * @return int
     */
    public function newSubscriptions(Bot $page, $date)
    {
        return $this->subscriberRepo->LastSubscribedAtCountForPage($date, $page);
    }

    /**
     * Count the number of subscribers who last unsubscribed in a given time period or on a specific date.
     *
     * @param Bot           $page
     * @param Carbon|string $date
     *
     * @return int
     */
    public function newUnsubscriptions(Bot $page, $date)
    {
        return $this->subscriberRepo->LastUnsubscribedAtCountForPage($date, $page);
    }

    /**
     * @param $attribute
     *
     * @return bool
     */
    private function fieldIsFilterable($attribute)
    {
        $allowed = in_array($attribute, array_keys($this->filterFieldsMap));

        return $allowed;
    }

    /**
     * @param Subscriber $subscriber
     * @param Bot        $bot
     */
    private function subscribeToBotSequences(Subscriber $subscriber, Bot $bot)
    {
        $sequencesToAdd = [];
        $sequences = $this->sequenceRepo->getAllForBot($bot);

        foreach ($sequences as $sequence) {

            if (in_array($sequence->id, $subscriber->sequences)) {
                continue;
            }

            if (in_array($sequence->id, $subscriber->removed_sequences)) {
                continue;
            }

            if ($this->subscriberRepo->subscriberMatchesRules($subscriber, $sequence->filter)) {
                continue;
            }

            $sequencesToAdd[] = $sequence->id;
        }

        if ($sequencesToAdd) {
            $this->subscribeToSequences($subscriber, $sequencesToAdd);
        }
    }

    /**
     * @param Subscriber $subscriber
     * @param array      $sequenceIds
     */
    private function subscribeToSequences(Subscriber $subscriber, array $sequenceIds)
    {
        $filter = [
            ['operator' => 'in', 'key' => '_id', 'value' => $sequenceIds],
            ['operator' => 'in', 'key' => 'bot_id', 'value' => $subscriber->bot_id]
        ];

        $sequences = $this->sequenceRepo->getAll($filter, [], ['_id', 'messages.id', 'messages.deleted_at', 'messages.queued']);

        $schedules = [];
        foreach ($sequences as $sequence) {
            if ($message = $this->sequenceRepo->getFirstSendableMessage($sequence)) {
                $schedules[] = [
                    'sequence_id'   => $sequence->_id,
                    'message_id'    => $message->id,
                    'subscriber_id' => $subscriber->_id,
                    'status'        => 'pending',
                    'send_at'       => change_date(Carbon::now(), $message->conditions['wait_for']),
                ];
            }

            // @todo use $inc to avoid any data inconsistency, and on the columns list remove 'messages.queues'.
            // @todo bulk increment if possible? like: increment the "queued" attribute of first sendable message (whose deleted_at is null) whose sequence ID is among $sequences.
            $index = $this->sequenceRepo->getMessageIndexInSequence($sequence, $message);
            $this->sequenceRepo->update($sequence, [
                "messages.{$index}.queued" => $message->queued + 1
            ]);
        }

        $this->sequenceScheduleRepo->bulkCreate($schedules);
    }
}