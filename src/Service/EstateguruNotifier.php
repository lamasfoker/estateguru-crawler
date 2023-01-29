<?php
declare(strict_types=1);

namespace App\Service;

use App\DomCrawler\CrawlerFactory;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class EstateguruNotifier
{
    private const ESTATEGURU_LOAN_VIEW_PAGE_REQUEST_URL = 'https://app.estateguru.co/investment/show/%s';

    private const ESTATEGURU_NEW_OPEN_LOANS_AJAX_REQUEST_URL = 'https://app.estateguru.co/investment/ajaxGetProjectMainList?filterTableId=dataTablePrimaryMarket&filter_interestRate=10&filter_ltvRatio=70&filter_currentCashType=APPROVED';

    private const TELEGRAM_SEND_MESSAGE_ENDPOINT = 'https://api.telegram.org/bot%s/sendMessage';

    private const ESTATEGURU_AVAIABLE_LOCATIONS = ['Lithuania', 'Estonia', 'Finland', 'Germany', 'Latvia', 'Netherlands', 'Portugal', 'Spain', 'Sweden', 'UK'];

    private const TEMPLATE_TELEGRAM_MESSAGE_FOUND = <<<TELEGRAM
🏠 <a href="%s">NUOVO PROGETTO</a> 🏠

💰 Interesse: <b>%s</b>
📊 LTV: <b>%s</b>
🕑 Durata: <b>%d mesi</b>
TELEGRAM;

    private HttpClientInterface $client;

    private CrawlerFactory $crawlerFactory;

    private string $myTelegramClientId;

    private string $estateguruCrawlerBotTelegramSecretToken;

    public function __construct(
        string $myTelegramClientId,
        string $estateguruCrawlerBotTelegramSecretToken,
        HttpClientInterface $client,
        CrawlerFactory $crawlerFactory
    ) {
        $this->client = $client;
        $this->crawlerFactory = $crawlerFactory;
        $this->myTelegramClientId = $myTelegramClientId;
        $this->estateguruCrawlerBotTelegramSecretToken = $estateguruCrawlerBotTelegramSecretToken;
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function notify(): void
    {
        try {
            $loansIds = $this->crawlLoansIds();
            $loans = $this->crawlLoans($loansIds);
            $filteredLoans = $this->filterLoans($loans);
            $this->sendLoans($filteredLoans);
        } catch (Throwable $e) {
            $this->sentTelegramMessage($e->getMessage());
        }
    }

    /**
     * @return string[]
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     */
    private function crawlLoansIds(): array
    {
        $response = $this->client->request('GET', self::ESTATEGURU_NEW_OPEN_LOANS_AJAX_REQUEST_URL);
        $crawler = $this->crawlerFactory->create();
        $crawler->addHtmlContent($response->getContent());
        return $crawler->filter('a.btn.btn-regular.w-100')->each(function (Crawler $link) {
            return explode('/', $link->attr('href'))[3];
        });
    }

    /**
     * @param array $ids
     * @return array
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function crawlLoans(array $ids): array
    {
        return array_map(function (string $id) {
            $url = sprintf(self::ESTATEGURU_LOAN_VIEW_PAGE_REQUEST_URL, $id);
            $response = $this->client->request('GET', $url);
            $crawler = $this->crawlerFactory->create();
            $crawler->addHtmlContent($response->getContent());
            $interest = $crawler->filter('#interestRateAmountBox');
            $crawler = $crawler->filter('div ul li .text-align-right');
            $ltv = $crawler->reduce(function (Crawler $node) {
                return strpos($node->text(), '%') !== false;
            })->first();
            $month = $crawler->reduce(function (Crawler $node) {
                return strpos($node->text(), 'months') !== false;
            });
            $rank = $crawler->reduce(function (Crawler $node) {
                return strpos($node->text(), 'rank') !== false;
            });
            $country = $crawler->reduce(function (Crawler $node) {
                return in_array($node->text(), self::ESTATEGURU_AVAIABLE_LOCATIONS, true);
            });
            return [
                'id' => $id,
                'url' => $url,
                'interest' => $interest->text(),
                'ltv' => $ltv->text(),
                'months' => (int)str_replace(' months', '', $month->text()),
                'rank' => $rank->text(),
                'location' => $country->text()
            ];
        }, $ids);
    }

    private function filterLoans(array $loans): array
    {
        return array_filter($loans, static function (array $loan) {
            if ($loan['months'] > 12) {
                return false;
            }
            if ($loan['rank'] !== 'First rank') {
                return false;
            }
            if (in_array($loan['location'], ['Germany', 'Finland', 'Lithuania'], true)) {
                return false;
            }
            return true;
        });
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function sendLoans(array $loans): void
    {
        foreach ($loans as $loan) {
            $this->sentTelegramMessage(sprintf(
                self::TEMPLATE_TELEGRAM_MESSAGE_FOUND,
                $loan['url'],
                $loan['interest'],
                $loan['ltv'],
                $loan['months']
            ));
        }
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function sentTelegramMessage(string $content): void
    {
        $endpoint = sprintf(self::TELEGRAM_SEND_MESSAGE_ENDPOINT, $this->estateguruCrawlerBotTelegramSecretToken);
        $this->client->request('POST', $endpoint, [
            'body' => [
                'chat_id' => $this->myTelegramClientId,
                'parse_mode' => 'HTML',
                'text' => $content
            ]
        ]);
    }
}
