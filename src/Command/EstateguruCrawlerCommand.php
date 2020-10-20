<?php

namespace App\Command;

use App\DomCrawler\CrawlerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class EstateguruCrawlerCommand extends Command
{
    private const ESTATEGURU_LOAN_VIEW_PAGE_REQUEST_URL = 'https://estateguru.co/portal/investment/show/%s';

    private const ESTATEGURU_NEW_OPEN_LOANS_AJAX_REQUEST_URL = 'https://estateguru.co/portal/investment/ajaxGetProjectMainList?filterTableId=dataTablePrimaryMarket&filter_interestRate=12&filter_ltvRatio=70&filter_currentCashType=APPROVED';

    private const TELEGRAM_SEND_MESSAGE_ENDPOINT = 'https://api.telegram.org/bot%s/sendMessage';

    private const TEMPLATE_TELEGRAM_MESSAGE = <<<TELEGRAM
🏠 <a href="%s">NUOVO PROGETTO</a> 🏠

💰 Interesse: <b>%s</b>
📊 LTV: <b>%s</b>
🕑 Durata: <b>%d mesi</b>
TELEGRAM;

    protected static $defaultName = 'lamasfoker:estateguru-crawl';

    /** @var HttpClientInterface */
    private $client;

    /** @var CrawlerFactory */
    private $crawlerFactory;

    /** @var string */
    private $myTelegramClientId;

    /** @var string */
    private $estateguruCrawlerBotTelegramSecretToken;

    public function __construct(
        string $myTelegramClientId,
        string $estateguruCrawlerBotTelegramSecretToken,
        HttpClientInterface $client,
        CrawlerFactory $crawlerFactory,
        string $name = null
    ) {
        $this->client = $client;
        $this->crawlerFactory = $crawlerFactory;
        $this->myTelegramClientId = $myTelegramClientId;
        $this->estateguruCrawlerBotTelegramSecretToken = $estateguruCrawlerBotTelegramSecretToken;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setDescription('Crawl from estateguru desiderable loans');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loansIds = $this->crawlLoansIds();
        $loans = $this->crawlLoans($loansIds);
        $filteredLoans = $this->filterLoans($loans);
        $this->sendTelegramMessages($filteredLoans);
        return Command::SUCCESS;
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
        $links = $crawler->filter('a.btn.btn-regular.w-100');
        return $links->each(function (Crawler $link) {
            return explode('/', $link->attr('href'))[4];
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
            return [
                'id' => $id,
                'url' => $url,
                'interest' => $interest->text(),
                'ltv' => $ltv->text(),
                'months' => (int)str_replace(' months', '', $month->text()),
                'rank' => $rank->text()
            ];
        }, $ids);
    }

    private function filterLoans(array $loans): array
    {
        return array_filter($loans, function (array $loan) {
            if ($loan['months'] > 12) {
                return false;
            }
            if ($loan['rank'] !== 'First rank') {
                return false;
            }
            return true;
        });
    }

    private function sendTelegramMessages(array $loans): void
    {
        $endpoint = sprintf(self::TELEGRAM_SEND_MESSAGE_ENDPOINT, $this->estateguruCrawlerBotTelegramSecretToken);
        foreach ($loans as $loan) {
            $this->client->request('POST', $endpoint, [
                'body' => [
                    'chat_id' => $this->myTelegramClientId,
                    'parse_mode' => 'HTML',
                    'text' => sprintf(
                        self::TEMPLATE_TELEGRAM_MESSAGE,
                        $loan['url'],
                        $loan['interest'],
                        $loan['ltv'],
                        $loan['months']
                    )
                ]
            ]);
        }
    }
}
