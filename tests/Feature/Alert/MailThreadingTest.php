<?php

namespace LibreNMS\Tests\Feature\Alert;

use App\Models\AlertTransport;
use Illuminate\Support\Facades\DB;
use LibreNMS\Alert\Transport\Mail;
use LibreNMS\Enum\AlertState;
use LibreNMS\Tests\TestCase;

final class MailThreadingTest extends TestCase
{
    private int $alertId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbSetUp();
        $this->alertId = DB::table('alerts')->insertGetId([
            'device_id' => 42,
            'rule_id' => 84,
            'state' => AlertState::ACTIVE,
            'alerted' => AlertState::CLEAR,
            'open' => 1,
            'info' => '{}',
        ]);
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
        $this->assertMatchesRegularExpression('/^<librenms-alert-[a-f0-9]{16}-42-84-' . $problemId . '@alerts\.librenms>$/', $problem['headers']['message_id']);
        $this->assertArrayNotHasKey('in_reply_to', $problem['headers']);
        $this->assertArrayNotHasKey('references', $problem['headers']);

        $ackId = $this->log(AlertState::ACKNOWLEDGED);
        $ackAlert = $this->alert($ackId, AlertState::ACKNOWLEDGED, AlertState::ACTIVE);
        $ackAlert['timestamp'] = 'later';
        $ack = $mail->thread($ackAlert);
        $this->assertSame($problem['headers']['message_id'], $ack['headers']['in_reply_to']);
        $this->assertSame($problem['headers']['message_id'], $ack['headers']['references']);
        $this->assertNotSame($problem['headers']['message_id'], $ack['headers']['message_id']);

        // Purging the root history must not change an active incident's thread.
        DB::table('alert_log')->where('id', $problemId)->delete();

        $recoveryId = $this->log(AlertState::RECOVERED);
        $recovery = $mail->thread($this->alert($recoveryId, AlertState::RECOVERED, AlertState::ACKNOWLEDGED));
        $this->assertSame($problem['headers']['message_id'], $recovery['headers']['in_reply_to']);
        $this->assertSame($problem['headers']['message_id'], $recovery['headers']['references']);
        $this->assertSame($problem['subject'], $ack['subject']);
        $this->assertSame($problem['subject'], $recovery['subject']);
        $this->assertSame("Incident $problemId at initial", $problem['subject']);

        $secondProblemId = $this->log(AlertState::ACTIVE);
        $secondProblem = $mail->thread($this->alert($secondProblemId, AlertState::ACTIVE, AlertState::CLEAR));
        $this->assertNotSame($problem['headers']['message_id'], $secondProblem['headers']['message_id']);
        $this->assertSame("Incident $secondProblemId at initial", $secondProblem['subject']);
    }

    public function testThreadingDisabledPreservesSubjectAndOmitsHeaders(): void
    {
        $setting = collect(Mail::configTemplate()['config'])->firstWhere('name', 'thread-notifications');
        $this->assertFalse($setting['default']);

        $mail = new class(new AlertTransport(['transport_config' => [
            'mail-contact' => 'email',
            'email' => 'alerts@example.net',
            'thread-notifications' => false,
        ]])) extends Mail
        {
            public array $sent = [];

            protected function send($emails, string $subject, string $message, bool $html, bool $bcc, ?bool $embedGraphs, ?array $headers): bool
            {
                $this->sent = compact('emails', 'subject', 'message', 'html', 'bcc', 'embedGraphs', 'headers');

                return true;
            }
        };
        $alert = $this->alert(123, AlertState::RECOVERED, AlertState::ACTIVE);
        $alert['title'] = 'Existing localized recovery subject';
        $alert['msg'] = 'Existing body';
        $alert['faults'] = [];
        $alert['transport_name'] = 'Mail test';

        $this->assertTrue($mail->deliverAlert($alert));
        $this->assertSame('Existing localized recovery subject', $mail->sent['subject']);
        $this->assertNull($mail->sent['headers']);
    }

    public function testThreadingSupportsSyntheticPayloadWithoutTemplate(): void
    {
        $mail = new class(new AlertTransport(['transport_config' => [
            'mail-contact' => 'email',
            'email' => 'alerts@example.net',
            'thread-notifications' => true,
        ]])) extends Mail
        {
            public array $sent = [];

            protected function send($emails, string $subject, string $message, bool $html, bool $bcc, ?bool $embedGraphs, ?array $headers): bool
            {
                $this->sent = compact('subject', 'headers');

                return true;
            }
        };
        $alert = [
            'uid' => 999999999,
            'device_id' => 42,
            'rule_id' => 84,
            'state' => AlertState::ACTIVE,
            'alerted' => AlertState::CLEAR,
            'title' => 'Synthetic transport test',
            'msg' => 'Test body',
            'faults' => [],
            'transport_name' => 'Mail test',
        ];

        $this->assertTrue($mail->deliverAlert($alert));
        $this->assertSame('Synthetic transport test', $mail->sent['subject']);
        $this->assertArrayHasKey('message_id', $mail->sent['headers']);
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
            'alert_id' => $this->alertId,
            'device_id' => 42,
            'rule_id' => 84,
            'state' => $state,
            'alerted' => $alerted,
            'display' => 'router.example.net',
            'name' => 'Packet loss',
            'title' => 'A localized state-specific title which must not be parsed',
            'timestamp' => 'initial',
            'template' => (object) [
                'title' => 'Incident {{ $alert->id }} at {{ $alert->timestamp }}',
                'title_rec' => 'Recovery title must not be used',
                'name' => 'Thread test',
            ],
        ];
    }
}
