<?php

namespace App\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\TransferStats;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use function count;

class DownloaderService
{
  private const BAD_WINDOWS_PATH_CHARS = ['<', '>', ':', '"', '/', '\\', '|', '?', '*'];

  /** @var SymfonyStyle $io */
  private $io;

  /** @var array $configs */
  private $configs;

  /** @var Client $client */
  private $client;

  /**
   * @param SymfonyStyle $io
   * @param array $configs
   */
  public function __construct(SymfonyStyle $io, array $configs)
  {
    $this->io = $io;
    $this->configs = $configs;
    $this->client = new Client([
      'base_uri' => $this->configs['URL'],
      'cookies' => TRUE,
    ]);
  }

  /**
   * @return void
   */
  public function download(): void
  {
    $this->login();

    $downloadPath = "{$this->configs['TARGET']}/symfonycasts";
    if (!is_dir($downloadPath) && !mkdir($downloadPath) && !is_dir($downloadPath)) {
      $this->io->error("Unable to create download directory '{$downloadPath}'");

      return;
    }

    $courses = $this->getCourses();
    $this->io->section('Wanted courses');
    $this->io->listing(array_keys($courses));

    $coursesCounter = 0;
    $coursesCount = count($courses);
    foreach ($courses as $title => $urls) {
      ++$coursesCounter;
      $this->io->newLine(3);
      $this->io->title("Processing course: '{$title}' ({$coursesCounter} of {$coursesCount})");
      $isCodeDownloaded = FALSE;
      $isScriptDownloaded = FALSE;

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

      foreach ($urls as $name => $url) {
        if (preg_match("/\/activity\/[0-9]{3}$/", $url)) {
          unset($urls[$name]);
        }
      }

      $chaptersCount = count($urls);
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
        foreach ($crawler->filter('[aria-labelledby="downloadDropdown"] a') as $i => $a) {
          $url = $a->getAttribute('href');
          $fileName = FALSE;
          switch ($url) {
            case (FALSE !== strpos($url, 'video')):
              $fileName = sprintf('%03d', $chaptersCounter) . "-{$name}.mp4";
              break;
            case (FALSE !== strpos($url, 'script') && !$isScriptDownloaded):
              $fileName = "{$titlePath}.pdf";
              $isScriptDownloaded = TRUE;
              break;
            case (FALSE !== strpos($url, 'code') && !$isCodeDownloaded):
              $fileName = "{$titlePath}.zip";
              $isCodeDownloaded = TRUE;
              break;
            case (FALSE !== strpos($url, 'script') && $isScriptDownloaded):
            case (FALSE !== strpos($url, 'code') && $isCodeDownloaded):
              $fileName = NULL;
              break;
            default:
              $this->io->warning('Unkown Link Type: ' . $url);
          }

          if ($fileName === NULL) {
            continue;
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
   * @return void
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
      throw new RuntimeException('Unable to authenticate');
    }

    $currentUrl = NULL;
    $this->client->post('login', [
      'form_params' => [
        'email' => $this->configs['EMAIL'],
        'password' => $this->configs['PASSWORD'],
        '_csrf_token' => $csrfToken,
      ],
      'on_stats' => function (TransferStats $stats) use (&$currentUrl) {
        $currentUrl = $stats->getEffectiveUri();
      },
    ]);

    if ((string)$currentUrl !== 'https://symfonycasts.com/') {
      throw new RuntimeException('Authorization failed.');
    }
  }

  /**
   * @return array
   */
  private function getCourses(): array
  {
    $courses = $this->fetchCourses();
    $whitelist = $this->configs['COURSES'];

    if (!empty($whitelist)) {
      foreach ($courses as $title => $lessons) {
        if (!in_array($title, $whitelist, TRUE)) {
          unset($courses[$title]);
        }
      }
    }

    return $courses;
  }

  /**
   * @return array
   */
  private function fetchCourses(): array
  {
    $this->io->title('Fetching courses...');

    $blueprintFile = __DIR__ . '/../blueprint.json';
    if (file_exists($blueprintFile)) {
      return json_decode(file_get_contents($blueprintFile), TRUE);
    }

    $response = $this->client->get('/courses/filtering');
    $courses = [];
    $crawler = new Crawler($response->getBody()->getContents());
    $elements = $crawler->filter('body > div.course-list-bookmark-container.js-course-item > div > div > a');
    $progressBar = $this->io->createProgressBar($elements->count());
    $progressBar->setFormat('<info>[%bar%]</info> %message%');
    $progressBar->start();

    foreach ($elements as $itemElement) {
      $titleElement = new Crawler($itemElement);
      $courseTitle = $titleElement->filter('img')->attr('alt');
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
   * @param string $text
   * @param bool $capitalizeFirstCharacter
   *
   * @return mixed|string
   */
  private function dashesToTitle($text, $capitalizeFirstCharacter = TRUE)
  {
    $str = str_replace('-', ' ', ucwords($text, '-'));

    if (!$capitalizeFirstCharacter) {
      $str = lcfirst($str);
    }

    return $str;
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
    $progressBar = NULL;
    $file = "{$filePath}/{$fileName}";

    try {
      $this->client->get($url, [
        'save_to' => $file,
        'allow_redirects' => ['max' => 2],
        'auth' => ['username', 'password'],
        'progress' => function ($total, $downloaded) use ($io, $fileName, &$progressBar) {
          if ($total && $progressBar === NULL) {
            $progressBar = $io->createProgressBar($total);
            $progressBar->setFormat("<info>[%bar%]</info> {$fileName}");
            $progressBar->start();
          }

          if ($progressBar !== NULL) {
            if ($total === $downloaded) {
              $progressBar->finish();

              return;
            }

            $progressBar->setProgress($downloaded);
          }
        },
      ]);
    } catch (Exception $e) {
      $this->io->warning($e->getMessage());

      unlink($file);
    }
  }
}
