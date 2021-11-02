<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber\Actions;

use App\Entity\User;
use App\Event\PageActionsEvent;

class UserFormsSubscriber extends AbstractActionsSubscriber
{
    public static function getActionName(): string
    {
        return 'user_forms';
    }

    public function onActions(PageActionsEvent $event): void
    {
        $payload = $event->getPayload();

        /** @var User $user */
        $user = $payload['user'];

        if ($user->getId() === null) {
            return;
        }

        if ($this->isGranted('edit', $user)) {
            $event->addAction('settings', ['url' => $this->path('user_profile_edit', ['username' => $user->getUsername()]), 'title' => 'edit', 'translation_domain' => 'actions']);
        }
        if ($this->isGranted('password', $user)) {
            $event->addAction('password', ['url' => $this->path('user_profile_password', ['username' => $user->getUsername()]), 'title' => 'profile.password']);
        }
        if ($this->isGranted('api-token', $user)) {
            $event->addAction('api-token', ['url' => $this->path('user_profile_api_token', ['username' => $user->getUsername()]), 'title' => 'profile.api-token']);
        }
        if ($this->isGranted('teams', $user)) {
            $event->addAction('teams', ['url' => $this->path('user_profile_teams', ['username' => $user->getUsername()]), 'title' => 'profile.teams']);
        }
        if ($this->isGranted('roles', $user)) {
            $event->addAction('roles', ['url' => $this->path('user_profile_roles', ['username' => $user->getUsername()]), 'title' => 'profile.roles']);
        }
    }
}
