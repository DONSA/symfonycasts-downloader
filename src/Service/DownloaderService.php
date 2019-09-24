<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;

class DownloaderService
{
    
     const BAD_WINDOWS_PATH_CHARS = ['<','>',':','"','/','\\','|','?','*'];
    
    /**
     * @var SymfonyStyle $io
     */
    private $io;

    /**
     * @var array $configs
     */
    private $configs;

    /**
     * @var Client $client
     */
    private $client;

    /**
     * App constructor.
     *
     * @param SymfonyStyle $io
     * @param array        $configs
     */
    public function __construct(SymfonyStyle $io, array $configs)
    {
        $this->io = $io;
        $this->configs = $configs;
        $this->client = new Client([
            'base_uri' => $this->configs['URL'],
            'cookies' => true
        ]);
    }

    /**
     * Download courses
     */
    public function download(): void
    {
        $this->login();

        $downloadPath = "{$this->configs['TARGET']}/symfonycasts";
        if (!is_dir($downloadPath) && !mkdir($downloadPath) && !is_dir($downloadPath)) {
            $this->io->error("Unable to create download directory '{$downloadPath}'");

            return;
        }

        $courses = $this->fetchCourses();
        $coursesWanted = (array) ($this->configs['COURSES'] ?? $courses);

        $courses = array_filter(
            $courses,
            function ($title) use ($coursesWanted) {
                return in_array($title, $coursesWanted);
            },
            ARRAY_FILTER_USE_KEY
        );

        $this->io->section('Wanted courses');
        $this->io->listing(array_keys($courses));

        $coursesCounter = 0;
        $coursesCount = \count($courses);
        foreach ($courses as $title => $urls) {
            ++$coursesCounter;
            $this->io->newLine(3);
            $this->io->title("Processing course: '{$title}' ({$coursesCounter} of {$coursesCount})");

            if (empty($urls)) {
                $this->io->warning('No chapters to download');

                continue;
            }

            $titlePath = str_replace(self::BAD_WINDOWS_PATH_CHARS, '-', $title);
            $coursePath = "{$downloadPath}/{$titlePath}";
            
            if (!is_dir($coursePath) && !mkdir($coursePath) && !is_dir($coursePath)) {
                $this->io->error('Unable to create course directory');

                continue;
            }

            $chaptersCounter = 0;
            $chaptersCount = \count($urls);
            foreach ($urls as $name => $url) {
                ++$chaptersCounter;
                $this->io->newLine();
                $this->io->section("Chapter '{$this->dashesToTitle($name)}' ({$chaptersCounter} of {$chaptersCount})");

                try {
                    $response = $this->client->get($url);
                } catch (ClientException $e) {
                    $this->io->error($e->getMessage());

                    continue;
                }

                $crawler = new Crawler($response->getBody()->getContents());
                foreach ($crawler->filter('[aria-labelledby="downloadDropdown"] > a') as $i => $a) {
                    $url = $a->getAttribute('href');
                    $fileName = false;
                    switch ($url) {
                        case (false !== strpos($url, 'video')):
                            $fileName = sprintf('%03d', $chaptersCounter) . "-{$name}.mp4";
                            break;
                        case (false !== strpos($url, 'script')):
                            $fileName = sprintf('%03d', $chaptersCounter) . "-{$name}-script.pdf";
                            break;
                            case (false !== strpos($url, 'code')):
                            $fileName = sprintf('%03d', $chaptersCounter) . "-{$name}-code.zip";
                            break;
                        default:
                            $this->io->warning('Unkown Link Type: ' . $url);
                    }

                    if (!$fileName) {
                        $this->io->warning('Unable to get download links');
                        continue;
                    }

                    if (file_exists("{$coursePath}/{$fileName}")) {
                        $this->io->writeln("File '{$fileName}' was already downloaded");

                        continue;
                    }

                    $this->downloadFile($a->getAttribute('href'), $coursePath, $fileName);
                    $this->io->newLine();
                }
            }
        }

        $this->io->success('Finished');
    }

    /**
     * @param string $url
     * @param string $filePath
     * @param string $fileName
     *
     * @return void
     */
    private function downloadFile($url, $filePath, $fileName): void
    {
        $io = $this->io;
        $progressBar = null;
        $file = "{$filePath}/{$fileName}";

        try {
            $this->client->get($url, [
                'save_to' => $file,
                'allow_redirects' => ['max' => 2],
                'auth' => ['username', 'password'],
                'progress' => function($total, $downloaded) use ($io, $fileName, &$progressBar) {
                    if ($total && $progressBar === null) {
                        $progressBar = $io->createProgressBar($total);
                        $progressBar->setFormat("<info>[%bar%]</info> {$fileName}");
                        $progressBar->start();
                    }

                    if ($progressBar !== null) {
                        if ($total === $downloaded) {
                            $progressBar->finish();

                            return;
                        }

                        $progressBar->setProgress($downloaded);
                    }
                }
            ]);
        } catch (\Exception $e) {
            $this->io->warning($e->getMessage());

            unlink($file);
        }
    }

    /**
     * Fetch courses
     *
     * @return array
     */
    private function fetchCourses(): array
    {
        $this->io->title('Fetching courses...');

        $blueprintFile = __DIR__ . '/../blueprint.json';
        if (file_exists($blueprintFile)) {
            return json_decode(file_get_contents($blueprintFile), true);
        }

        $response = $this->client->get('/courses/filtering');

        $courses = [];
        $crawler = new Crawler($response->getBody()->getContents());
        $elements = $crawler->filter('.js-course-item > a');

        $progressBar = $this->io->createProgressBar($elements->count());
        $progressBar->setFormat('<info>[%bar%]</info> %message%');
        $progressBar->start();

        foreach ($elements as $itemElement) {

            $titleElement = new Crawler($itemElement);
            $courseTitle = $titleElement->filter('.course-list-item-title')->text();
            $courseUri = $itemElement->getAttribute('href');

            $progressBar->setMessage($courseTitle);
            $progressBar->advance();

            $chapters = [];
            $response = $this->client->get($courseUri);
            $crawler = new Crawler($response->getBody()->getContents());
            foreach ($crawler->filter('ul.chapter-list > li > a') as $a) {
                if ($a->getAttribute('href') === '#') {
                    continue;
                }

                $url = explode('#', $a->getAttribute('href'))[0];
                $urlParts = explode('/', $url);

                $chapters[end($urlParts)] = $url;
            }

            $courses[$courseTitle] = $chapters;
        }

        $progressBar->finish();

        if (!file_put_contents($blueprintFile, json_encode($courses, JSON_PRETTY_PRINT))) {
            $this->io->warning('Unable to save course blueprint');
        }

        return $courses;
    }

    /**
     * Login
     */
    private function login(): void
    {
        $response = $this->client->get('login');

        $csrfToken = '';
        $crawler = new Crawler($response->getBody()->getContents());
        foreach ($crawler->filter('input') as $input) {
            if ($input->getAttribute('name') === '_csrf_token') {
                $csrfToken = $input->getAttribute('value');
            }
        }

        if (empty($csrfToken)) {
            throw new \RuntimeException('Unable to authenticate');
        }

        $currentUrl = null;
        $this->client->post('login_check', [
            'form_params' => [
                '_email' => $this->configs['EMAIL'],
                '_password' => $this->configs['PASSWORD'],
                '_csrf_token' => $csrfToken
            ],
            'on_stats' => function(TransferStats $stats) use (&$currentUrl) {
                $currentUrl = $stats->getEffectiveUri();
            }
        ]);

        if ((string) $currentUrl !== 'https://symfonycasts.com/') {
            throw new \RuntimeException('Authorization failed.');
        }
    }

    /**
     * Convert dash to title
     *
     * @param string $text
     * @param bool   $capitalizeFirstCharacter
     *
     * @return mixed|string
     */
    private function dashesToTitle($text, $capitalizeFirstCharacter = true)
    {
        $str = str_replace('-', ' ', ucwords($text, '-'));

        if (!$capitalizeFirstCharacter) {
            $str = lcfirst($str);
        }

        return $str;
    }
}
