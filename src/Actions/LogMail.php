<?php

namespace Vormkracht10\Mails\Actions;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Vormkracht10\Mails\Shared\AsAction;

class LogMail
{
    use AsAction;

    public function handle(MessageSending|MessageSent $event): void
    {
        if (! config('mails.logging.enabled')) {
            return;
        }

        $mail = $this->newMailModelInstance();

        if ($event instanceof MessageSending) {
            $mail->fill($this->getOnlyConfiguredAttributes($event));
            $mail->save();

            $this->collectAttachments($mail, $event->message->getAttachments());

            $this->attachMailable($mail);
        }

        if ($event instanceof MessageSent) {
            $mail = $mail->firstWhere('uuid', $this->getCustomUuid($event));

            $mail->update($this->getOnlyConfiguredAttributes($event));
        }
    }

    /**
     * @return \Vormkracht10\Mails\Models\Mail
     */
    public function newMailModelInstance()
    {
        $model = config('mails.models.mail');

        return new $model;
    }

    public function getOnlyConfiguredAttributes(MessageSending|MessageSent $event): array
    {
        return collect($this->getDefaultLogAttributes($event))
            ->only($this->getConfiguredAttributes())
            ->merge($this->getMandatoryAttributes($event))
            ->toArray();
    }

    public function getConfiguredAttributes(): array
    {
        return (array) config('mails.logging.attributes');
    }

    public function getDefaultLogAttributes(MessageSending|MessageSent $event): array
    {
        return [
            'subject' => $event->message->getSubject(),
            'from' => $this->getAddressesValue($event->message->getFrom()),
            'reply_to' => $this->getAddressesValue($event->message->getReplyTo()),
            'to' => $this->getAddressesValue($event->message->getTo()),
            'cc' => $this->getAddressesValue($event->message->getCc()),
            'bcc' => $this->getAddressesValue($event->message->getBcc()),
            'html' => $event->message->getHtmlBody(),
            'text' => $event->message->getTextBody(),
        ];
    }

    public function getMandatoryAttributes(MessageSending|MessageSent $event): array
    {
        return [
            'uuid' => $this->getCustomUuid($event),
            // 'mail_class' => $this->getMailClassHeaderValue($event),
            'sent_at' => $event instanceof MessageSent ? now() : null,
        ];
    }

    protected function getCustomUuid(MessageSending|MessageSent $event): ?string
    {
        if (! $event->message->getHeaders()->has(config('mails.headers.uuid'))) {
            return null;
        }

        $headerValue = $event->message->getHeaders()->get(config('mails.headers.uuid'));

        if (is_null($headerValue)) {
            return null;
        }

        return $headerValue->getBodyAsString();
    }

    protected function getAddressesValue(array $address): ?Collection
    {
        $addresses = collect($address)
            ->flatMap(fn (Address $address) => [$address->getAddress() => $address->getName() === '' ? null : $address->getName()]);

        return $addresses->count() > 0 ? $addresses : null;
    }

    /**
     * @param \Vormkracht10\Mails\Models\Mail
     */
    public function collectAttachments($mail, $attachments): void
    {
        collect($attachments)->each(function ($attachment) use ($mail) {
            $attachmentModel = $mail->attachments()->create([
                'disk' => config('mails.logging.attachments.disk'),
                'uuid' => $attachment->getContentId(),
                'filename' => $attachment->getFilename(),
                'mime' => $attachment->getContentType(),
                'inline' => ! str_contains($attachment->getFilename() ?: '', '.'), // workaround, because disposition is a private property
                'size' => strlen($attachment->getBody()),
            ]);

            $this->saveAttachment($attachmentModel, $attachment);
        });
    }

    public function saveAttachment($attachmentModel, $attachment): void
    {
        Storage::disk($attachmentModel->disk)
            ->put($this->getAttachmentStoragePath($attachmentModel->id), $attachment->getBody(), 'private');
    }

    public function getAttachmentStoragePath(string $filename): string
    {
        return rtrim(config('mails.logging.attachments.root'), '/').'/'.$filename;
    }

    public function attachMailable($mail, $email_address = null): void
    {
        if (! config('mails.mailable.model')) {
            return;
        }

        if (! $email_address) {
            $email_address = array_key_first($mail->to);
        }

        $mailableModel = config('mails.mailable.model')::where(config('mails.mailable.email_field'), $email_address)->first();

        if ($mailableModel && method_exists($mailableModel, 'mails') ) {
            $mailableModel->mails()->attach($mail);
        }

    }
}
