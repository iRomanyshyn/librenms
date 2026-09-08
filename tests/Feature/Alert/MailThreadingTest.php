<?php

namespace LibreNMS\Tests\Feature\Alert;

use Illuminate\Support\Facades\DB;
use LibreNMS\Alert\Transport\Mail;
use LibreNMS\Enum\AlertState;
use LibreNMS\Tests\TestCase;

final class MailThreadingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dbSetUp();
    }

    protected function tearDown(): void
    {
        $this->dbTearDown();
        parent::tearDown();
    }

    public function testNotificationsAreThreadedByIncident(): void
    {
        $mail = new class extends Mail
        {
            public function thread(array $alert): array
            {
                return $this->threadingData($alert);
            }
        };

        $problemId = $this->log(AlertState::ACTIVE);
        $problem = $mail->thread($this->alert($problemId, AlertState::ACTIVE, AlertState::CLEAR));
        $this->assertMatchesRegularExpression('/^<librenms-alert-42-84-' . $problemId . '@[^>]+>$/', $problem['headers']['message_id']);
        $this->assertArrayNotHasKey('in_reply_to', $problem['headers']);
        $this->assertArrayNotHasKey('references', $problem['headers']);

        $ackId = $this->log(AlertState::ACKNOWLEDGED);
        $ack = $mail->thread($this->alert($ackId, AlertState::ACKNOWLEDGED, AlertState::ACTIVE));
        $this->assertSame($problem['headers']['message_id'], $ack['headers']['in_reply_to']);
        $this->assertSame($problem['headers']['message_id'], $ack['headers']['references']);
        $this->assertNotSame($problem['headers']['message_id'], $ack['headers']['message_id']);

        $recoveryId = $this->log(AlertState::RECOVERED);
        $recovery = $mail->thread($this->alert($recoveryId, AlertState::RECOVERED, AlertState::ACKNOWLEDGED));
        $this->assertSame($problem['headers']['message_id'], $recovery['headers']['in_reply_to']);
        $this->assertSame($problem['headers']['message_id'], $recovery['headers']['references']);
        $this->assertSame($problem['subject'], $ack['subject']);
        $this->assertSame($problem['subject'], $recovery['subject']);
        $this->assertSame("Incident $problemId", $problem['subject']);

        $secondProblemId = $this->log(AlertState::ACTIVE);
        $secondProblem = $mail->thread($this->alert($secondProblemId, AlertState::ACTIVE, AlertState::CLEAR));
        $this->assertNotSame($problem['headers']['message_id'], $secondProblem['headers']['message_id']);
        $this->assertSame("Incident $secondProblemId", $secondProblem['subject']);
    }

    public function testThreadingIsDisabledByDefault(): void
    {
        $setting = collect(Mail::configTemplate()['config'])->firstWhere('name', 'thread-notifications');

        $this->assertFalse($setting['default']);
    }

    private function log(int $state): int
    {
        return DB::table('alert_log')->insertGetId([
            'device_id' => 42,
            'rule_id' => 84,
            'state' => $state,
            'time_logged' => now(),
        ]);
    }

    private function alert(int $id, int $state, int $alerted): array
    {
        return [
            'uid' => $id,
            'id' => $id,
            'device_id' => 42,
            'rule_id' => 84,
            'state' => $state,
            'alerted' => $alerted,
            'display' => 'router.example.net',
            'name' => 'Packet loss',
            'title' => 'A localized state-specific title which must not be parsed',
            'template' => (object) [
                'title' => 'Incident {{ $alert->id }}',
                'title_rec' => 'Recovery title must not be used',
                'name' => 'Thread test',
            ],
        ];
    }
}
