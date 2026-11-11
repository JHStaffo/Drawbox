<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Slack;
use Illuminate\Http\Request;
use RuntimeException;

/** Connects Pulse to Slack with a Slack app's bot token. */
class SlackController extends Controller
{
    public function edit(Request $request, Slack $slack)
    {
        return view('admin.slack', [
            'connected' => $slack->connected(),
            'masked' => $slack->maskedToken(),
            'workspace' => $slack->workspace(),
            'channels' => $slack->channels($request->boolean('refresh')),
        ]);
    }

    public function update(Request $request, Slack $slack)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^xoxb-[A-Za-z0-9-]+$/'],
        ], ['token.regex' => 'That isn\'t a bot token. It starts with xoxb- and is on the Slack app\'s OAuth & Permissions page.']);
        $token = trim($data['token']);

        try {
            $who = $slack->identify($token);
        } catch (RuntimeException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }
        $slack->saveToken($token);

        return back()->with('status', "Connected to the {$who['team']} Slack workspace as {$who['bot']}.");
    }

    public function destroy(Slack $slack)
    {
        $slack->saveToken(null);

        return back()->with('status', 'Disconnected Slack. Forms keep saving, but won\'t post until it\'s connected again.');
    }

    public function test(Request $request, Slack $slack)
    {
        $data = $request->validate(['channel' => 'required|string|max:100']);
        $channel = trim($data['channel']);
        $label = isset($slack->channels()[$channel]) ? '#'.$slack->channels()[$channel] : $channel;

        try {
            $slack->post($channel, 'Test message from Fast Track Pulse. Slack is connected.', [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => ':white_check_mark: *Test message from Fast Track Pulse.* Slack is connected and can post here.']],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => Slack::escape('Sent by '.$request->user()->name.' · '.now()->format('M j, Y g:i A'))]]],
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', "Test to {$label} didn't go through. ".$e->getMessage());
        }

        return back()->withInput()->with('status', "Test message sent to {$label}. Check Slack.");
    }
}
