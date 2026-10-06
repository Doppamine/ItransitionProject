<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

final readonly class SalesforceAccountContactCreator
{
    public function __construct(private SalesforceClient $client)
    {
    }

    public function create(SalesforceAccountContactInput $input): SalesforceAccountContactResult
    {
        $payload = [
            'allOrNone' => true,
            'compositeRequest' => [
                [
                    'method' => 'POST',
                    'url' => $this->client->apiPath('sobjects/Account'),
                    'referenceId' => 'account',
                    'body' => $this->fieldsWithValues([
                        'Name' => $input->companyName,
                        'Website' => $input->website,
                    ]),
                ],
                [
                    'method' => 'POST',
                    'url' => $this->client->apiPath('sobjects/Contact'),
                    'referenceId' => 'contact',
                    'body' => $this->fieldsWithValues([
                        'AccountId' => '@{account.id}',
                        'FirstName' => $input->firstName,
                        'LastName' => $input->lastName,
                        'Email' => $input->email,
                        'Title' => $input->jobTitle,
                        'Phone' => $input->phone,
                        'MailingCity' => $input->location,
                    ]),
                ],
            ],
        ];

        try {
            $response = $this->client->request('POST', 'composite', $payload);
        } catch (SalesforceAuthenticationException|SalesforceApiException) {
            throw new SalesforceCompositeException();
        }

        return $this->resultFromResponse($response);
    }

    private function fieldsWithValues(array $fields): array
    {
        return array_filter($fields, static fn (?string $value): bool => $value !== null && trim($value) !== '');
    }

    private function resultFromResponse(array $response): SalesforceAccountContactResult
    {
        $results = $response['compositeResponse'] ?? null;
        if (!is_array($results) || !array_is_list($results) || count($results) !== 2) {
            throw new SalesforceCompositeException();
        }

        $ids = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                throw new SalesforceCompositeException();
            }

            $reference = $result['referenceId'] ?? null;
            $status = $result['httpStatusCode'] ?? null;
            $body = $result['body'] ?? null;
            if (!in_array($reference, ['account', 'contact'], true) || isset($ids[$reference])
                || !is_int($status) || $status < 200 || $status >= 300 || !is_array($body)) {
                throw new SalesforceCompositeException();
            }

            $id = $body['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-zA-Z0-9]{15}(?:[a-zA-Z0-9]{3})?$/D', $id) !== 1
                || (array_key_exists('success', $body) && $body['success'] !== true)
                || (array_key_exists('errors', $body) && $body['errors'] !== [])) {
                throw new SalesforceCompositeException();
            }

            $ids[$reference] = $id;
        }

        return new SalesforceAccountContactResult($ids['account'], $ids['contact']);
    }
}
