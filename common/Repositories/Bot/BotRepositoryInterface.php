<?php namespace Common\Repositories\Bot;

use Common\Models\Bot;
use Common\Models\User;
use Common\Models\Button;
use Common\Models\Subscriber;
use Illuminate\Support\Collection;
use Common\Repositories\BaseRepositoryInterface;

interface BotRepositoryInterface extends BaseRepositoryInterface
{

    /**
     * Find a page by its Facebook id.
     * @param      $botId
     * @param User $user
     * @return Bot|null
     */
    public function findByIdForUser($botId, User $user);

    /**
     * Find a page by its Facebook id.
     * @param $id
     * @return Bot
     */
    public function findByFacebookId($id);

    /**
     * Get the list of active pages that belong to a user.
     * @param User $user
     * @return Collection
     */
    public function getEnabledForUser(User $user);

    /**
     * Get the list of inactive pages that belong to a user.
     * @param User $user
     * @return Collection
     */
    public function getDisabledForUser(User $user);

    /**
     * @param array $botIds
     * @param User  $user
     */
    public function addUserToBots(array $botIds, User $user);

    /**
     * @param User $user
     * @param Bot  $bot
     * @return Subscriber
     */
    public function getSubscriberForUser(User $user, Bot $bot);


    /**
     * @param User       $user
     * @param Subscriber $subscriber
     * @param Bot        $bot
     */
    public function setSubscriberForUser(User $user, Subscriber $subscriber, Bot $bot);

    /**
     * @param       $botId
     * @param array $tags
     */
    public function createTagsForBot($botId, array $tags);

    /**
     * @param Bot    $bot
     * @param Button $button
     */
    public function incrementMainMenuButtonClicks(Bot $bot, Button $button);
}
