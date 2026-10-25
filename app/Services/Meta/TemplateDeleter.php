<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Deletes one language of a WhatsApp template from Meta.
 *
 * Meta needs both the template name and its id when only one language should be
 * removed; passing just the name deletes every translation of that template.
 */
class TemplateDeleter
{
    /** @return bool Whether Meta was asked to delete it (false for local-only rows). */
    public function delete(MessageTemplate $template): bool
    {
        $account = $template->channelAccount;

        if (! $account) {
            throw new RuntimeException('The WhatsApp account for this template no longer exists.');
        }

        if (! $template->meta_template_id) {
            return false;
        }

        if (! $account->business_account_id) {
            throw new RuntimeException('No WhatsApp Business Account ID is saved for this number.');
        }

        $token = ChannelAccount::normaliseToken($account->access_token);

        if (! $token) {
            throw new RuntimeException('No access token is saved for this WhatsApp number.');
        }

        $base = rtrim((string) config('omniflow.meta.hosts.whatsapp', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('omniflow.meta.graph_version'), '/');
        $query = http_build_query([
            'name' => $template->name,
            'hsm_id' => $template->meta_template_id,
        ]);

        $response = Http::withToken($token)
            ->connectTimeout(5)
            ->timeout(20)
            ->delete("{$base}/{$version}/{$account->business_account_id}/message_templates?{$query}");

        if ($response->status() === 404) {
            return true;
        }

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json()) ?? 'Meta rejected the delete request.');
        }

        if ($response->json('success') !== true) {
            throw new RuntimeException('Meta did not confirm the template was deleted.');
        }

        return true;
    }

    /** @param array<string, mixed>|null $body */
    private function reason(?array $body): ?string
    {
        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        foreach (['error_user_msg', 'error_user_title', 'message'] as $key) {
            $message = $error[$key] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return null;
    }
}
