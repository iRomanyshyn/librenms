<?php

/* Copyright (C) 2014 Daniel Preussker <f0o@devilcode.org>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>. */

/**
 * Mail Transport
 *
 * @author f0o <f0o@devilcode.org>
 * @copyright 2014 f0o, LibreNMS
 * @license GPL
 */

namespace LibreNMS\Alert\Transport;

use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Models\Eventlog;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LibreNMS\Alert\AlertUtil;
use LibreNMS\Alert\Template;
use LibreNMS\Alert\Transport;
use LibreNMS\Enum\AlertState;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\AlertTransportDeliveryException;
use Spatie\Permission\Models\Role;

class Mail extends Transport
{
    public function deliverAlert(array $alert_data): bool
    {
        $emails = match ($this->config['mail-contact'] ?? '') {
            'sysContact' => AlertUtil::findContactsSysContact($alert_data['faults']),
            'owners' => AlertUtil::findContactsOwners($alert_data['faults']),
            'role' => AlertUtil::findContactsRoles([$this->config['role']]),
            default => $this->config['email'] ?? $alert_data['contacts'] ?? [], // contacts is only used by legacy synthetic transport
        };

        if (is_array($emails) && count($emails) == 0) {
            $device = DeviceCache::get($alert_data['device_id']);
            Eventlog::log('No e-mail recipients found for transport ' . $alert_data['transport_name'], $device, 'alert', Severity::Notice);

            return true;
        }

        $html = LibrenmsConfig::get('email_html');

        if ($html && ! $this->isHtmlContent($alert_data['msg'])) {
            // if there are no html tags in the content, but we are sending an html email, use br for line returns instead
            $msg = preg_replace("/\r?\n/", "<br />\n", (string) $alert_data['msg']);
        } else {
            // fix line returns for windows mail clients
            $msg = preg_replace("/(?<!\r)\n/", "\r\n", (string) $alert_data['msg']);
        }

        try {
            $thread = ! empty($this->config['thread-notifications']) ? $this->threadingData($alert_data) : null;

            return \LibreNMS\Util\Mail::send(
                $emails,
                $thread['subject'] ?? $alert_data['title'],
                $msg,
                $html,
                $this->config['bcc'] ?? false,
                $this->config['attach-graph'] ?? null,
                $thread['headers'] ?? null
            );
        } catch (Exception $e) {
            throw new AlertTransportDeliveryException($alert_data, 0, $e->getMessage());
        }
    }

    /**
     * Build the canonical subject and RFC thread headers for this alert incident.
     * A recovered log row terminates the preceding incident, so the first row after
     * the previous recovery is the root even when alerts.id is reused.
     *
     * @return array{subject: string, headers: array{message_id: string, in_reply_to?: string, references?: string}}
     */
    protected function threadingData(array $alert_data): array
    {
        $logId = (int) $alert_data['uid'];
        $previousRecovery = DB::table('alert_log')
            ->where('device_id', $alert_data['device_id'])
            ->where('rule_id', $alert_data['rule_id'])
            ->where('state', AlertState::RECOVERED)
            ->where('id', '<', $logId)
            ->max('id') ?? 0;
        $rootId = (int) DB::table('alert_log')
            ->where('device_id', $alert_data['device_id'])
            ->where('rule_id', $alert_data['rule_id'])
            ->where('state', '!=', AlertState::RECOVERED)
            ->where('id', '>', $previousRecovery)
            ->where('id', '<=', $logId)
            ->min('id');

        // Be defensive for synthetic/test notifications which have no alert_log row.
        $rootId = $rootId ?: $logId;
        $rootMessageId = sprintf(
            '<librenms-alert-%d-%d-%d@%s>',
            $alert_data['device_id'],
            $alert_data['rule_id'],
            $rootId,
            $this->messageIdHost()
        );
        $initial = $logId === $rootId
            && (int) $alert_data['state'] === AlertState::ACTIVE
            && (int) ($alert_data['alerted'] ?? AlertState::CLEAR) !== AlertState::ACTIVE;
        $headers = ['message_id' => $initial ? $rootMessageId : sprintf(
            '<librenms-alert-%d-%s@%s>',
            $rootId,
            bin2hex(random_bytes(12)),
            $this->messageIdHost()
        )];
        if (! $initial) {
            $headers['in_reply_to'] = $rootMessageId;
            $headers['references'] = $rootMessageId;
        }

        $subjectAlert = $alert_data;
        $subjectAlert['id'] = $rootId;
        $subjectAlert['state'] = AlertState::ACTIVE;
        $subjectAlert['title'] = $alert_data['template']->title
            ?: 'Alert for device ' . $alert_data['display'] . ' - ' . $alert_data['name'];

        return [
            'subject' => (new Template)->getTitle([
                'alert' => $subjectAlert,
                'title' => $subjectAlert['title'],
                'name' => $alert_data['template']->name,
            ]),
            'headers' => $headers,
        ];
    }

    private function messageIdHost(): string
    {
        return trim((string) preg_replace('/[^a-z0-9.-]+/i', '-', gethostname()), '-.') ?: 'localhost';
    }

    public static function configTemplate(): array
    {
        $roles = ['None' => ''];
        foreach (Role::query()->pluck('name')->all() as $name) {
            $roles[$name] = Str::title(str_replace('-', ' ', $name));
        }

        return [
            'config' => [
                [
                    'title' => 'Contact Type',
                    'name' => 'mail-contact',
                    'descr' => 'Method for selecting contacts',
                    'type' => 'select',
                    'options' => [
                        'Specified Email' => 'email',
                        'Device sysContact' => 'sysContact',
                        'Owner(s)' => 'owners',
                        'Role' => 'role',
                    ],
                    'default' => 'email',
                ],
                [
                    'title' => 'Email',
                    'name' => 'email',
                    'descr' => 'Email address of contact',
                    'type' => 'text',
                ],
                [
                    'title' => 'Role',
                    'name' => 'role',
                    'descr' => 'Role of users to mail',
                    'type' => 'select',
                    'options' => $roles,
                ],
                [
                    'title' => 'BCC',
                    'name' => 'bcc',
                    'descr' => 'Use BCC instead of TO',
                    'type' => 'checkbox',
                    'default' => false,
                ],
                [
                    'title' => 'Include Graphs',
                    'name' => 'attach-graph',
                    'descr' => 'Include graph image data in the email.  Will be embedded if html5, otherwise attached. Template must use @signedGraphTag',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'title' => 'Thread notifications',
                    'name' => 'thread-notifications',
                    'descr' => 'Group all notifications for an alert incident into one email thread',
                    'type' => 'checkbox',
                    'default' => false,
                ],
            ],
            'validation' => [
                'mail-contact' => 'required|in:email,sysContact,owners,role',
                'email' => 'required_if:mail-contact,email|prohibited_unless:mail-contact,email|email',
                'role' => 'required_if:mail-contact,role|prohibited_unless:mail-contact,role|exists:roles,name',
            ],
        ];
    }
}
