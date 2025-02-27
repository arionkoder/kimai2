<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Kernel;
use App\Utils\LanguageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Command used to execute all the basic application bootstrapping AFTER "composer install" was executed.
 *
 * @codeCoverageIgnore
 */
class TranslationCommand extends Command
{
    private $projectDirectory;
    private $environment;
    private $languageService;

    public function __construct(string $projectDirectory, string $kernelEnvironment, LanguageService $languageService)
    {
        parent::__construct();
        $this->projectDirectory = $projectDirectory;
        $this->environment = $kernelEnvironment;
        $this->languageService = $languageService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('kimai:translations')
            ->setDescription('Translation adjustments')
            ->addOption('resname', null, InputOption::VALUE_NONE, 'Fix the resname vs. id attribute')
            ->addOption('duplicates', null, InputOption::VALUE_NONE, 'Find duplicate translation keys')
            ->addOption('delete-resname', null, InputOption::VALUE_REQUIRED, 'Deletes the translation by resname')
            ->addOption('extension', null, InputOption::VALUE_NONE, 'Find translation files with wrong extensions')
            ->addOption('translate-locale', null, InputOption::VALUE_REQUIRED, 'Translate into the given locale')
            // @see https://www.deepl.com/de/pro#developer
            ->addOption('translate-deepl', null, InputOption::VALUE_REQUIRED, 'Translate using the "DeepL API Free" auth-key')
        ;
    }

    public function isEnabled(): bool
    {
        return $this->environment !== 'prod';
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $io = new SymfonyStyle($input, $output);

        $bases = [
            'core' => $this->projectDirectory . '/translations',
            'plugins' => $this->projectDirectory . Kernel::PLUGIN_DIRECTORY . '/*/Resources/translations',
        ];

        if ($input->getOption('delete-resname')) {
            $files = glob($bases['core'] . '/*.xlf');
            foreach ($files as $file) {
                $this->removeKey($file, $input->getOption('delete-resname'));
            }
        }

        if ($input->getOption('resname')) {
            foreach ($bases as $id => $directory) {
                $files = glob($directory . '/*.xlf');

                foreach ($files as $file) {
                    $this->fixXlfFile($file);
                }
            }
        }

        if ($input->getOption('extension')) {
            foreach ($bases as $id => $directory) {
                $files = glob($directory . '/*.xliff');

                foreach ($files as $file) {
                    $file = str_replace($this->projectDirectory, '', $file);
                    $io->warning($file);
                }
            }
        }

        if ($input->getOption('duplicates')) {
            $duplicates = [];

            $files = glob($bases['core'] . '/*.xlf');
            foreach ($files as $file) {
                $xml = simplexml_load_file($file);
                foreach ($xml->file->body->{'trans-unit'} as $unit) {
                    $n = (string) $unit['resname'];
                    if (!\array_key_exists($n, $duplicates)) {
                        $duplicates[$n] = [];
                    }
                    $b = explode('.', basename($file))[0];
                    if (!\in_array($b, $duplicates[$n])) {
                        $duplicates[$n][] = $b;
                    }
                }
            }

            foreach ($duplicates as $id => $files) {
                if (\count($files) > 1) {
                    $io->text($id . ' => ' . implode(', ', $files));
                }
            }
        }

        $locale = $input->getOption('translate-locale');
        $deepl = $input->getOption('translate-deepl');

        if ($locale !== null && $deepl === null) {
            $io->error('Missing "DeepL API Free" auth-key');

            return 1;
        }

        if ($locale === null && $deepl !== null) {
            $io->error('Missing translation locale');

            return 1;
        }

        if ($locale !== null && $deepl !== null) {
            // see https://github.com/octfx/DeepLy/blob/master/src/DeepLy.php
            $deeplySupportedLanguages = [
                'de' => 'DE',
                'en' => 'EN-US',
                'fr' => 'FR',
                'it' => 'IT',
                'ja' => 'JA',
                'es' => 'ES',
                'nl' => 'NL',
                'pl' => 'PL',
                'pt' => 'PT-PT', // ???
                'pt_BR' => 'PT-BR', // ???
                'ru' => 'RU',
                'zh_CN' => 'ZH',
            ];

            $locale = strtolower($locale);
            if (!$this->languageService->isKnownLanguage($locale)) {
                $io->error('Unknown locale given: ' . $locale);

                return 1;
            }

            if (!\array_key_exists($locale, $deeplySupportedLanguages)) {
                $io->error('Locale not supported by Deeply: ' . $locale);

                return 1;
            }

            $allKeys = 0;
            $enFiles = glob($bases['core'] . '/*.en.xlf');

            $baseUrl = 'https://api-free.deepl.com/v2/translate';
            $client = HttpClient::create([]);

            foreach ($enFiles as $file) {
                $enTrans = [];
                $domain = explode('.', basename($file))[0];

                $xml = simplexml_load_file($file);

                foreach ($xml->file->body->{'trans-unit'} as $unit) {
                    $id = (string) $unit['id'];
                    $enTrans[$id] = [
                        'resname' => (string) $unit['resname'],
                        'source' => (string) $unit->source,
                        'target' => (string) $unit->target
                    ];
                    $allKeys++;
                }

                $localeFile = $bases['core'] . '/' . $domain . '.' . $locale . '.xlf';

                $translated = [];

                if (file_exists($localeFile)) {
                    $xml2 = simplexml_load_file($localeFile);
                    foreach ($xml2->file->body->{'trans-unit'} as $unit) {
                        $id = (string) $unit['id'];
                        $translated[$id] = [
                            'resname' => (string) $unit['resname'],
                            'source' => (string) $unit->source,
                            'target' => (string) $unit->target
                        ];
                    }
                }

                $missingIds = array_diff(array_keys($enTrans), array_keys($translated));
                if (\count($missingIds) === 0) {
                    continue;
                }

                $io->title('Translating ' . $domain);
                $progress = new ProgressBar($output, \count($missingIds));

                foreach ($missingIds as $id) {
                    $progress->advance();

                    $values = $enTrans[$id];
                    $translated[$id] = $values;

                    $params = [
                        'auth_key' => $deepl,
                        //'split_sentences' => '1',
                        //'preserve_formatting' => '0',
                        'formality' => 'default',
                        'text' => $values['target'],
                        'source_lang' => 'en',
                        'target_lang' => $deeplySupportedLanguages[$locale],
                    ];

                    $rawResponseData = null;
                    try {
                        $rawResponseData = $client->request('POST', $baseUrl, ['body' => $params]);
                    } catch (\Exception $exception) {
                        $io->error($exception->getMessage());

                        return 1;
                    }

                    $json = json_decode($rawResponseData->getContent(), true);
                    $translation = $json['translations'][0]['text'];

                    $translated[$id]['target'] = $translation;
                }

                $progress->finish();
                $io->writeln(PHP_EOL);

                $this->writeXliffFile($bases['core'], $domain, $locale, $translated);
            }
        }

        return 0;
    }

    private function writeXliffFile(string $base, string $domain, string $locale, array $translations = []): void
    {
        $from = $base . '/' . $domain . '.en.xlf';
        $to = $base . '/' . $domain . '.' . $locale . '.xlf';

        copy($from, $to);

        $xml = simplexml_load_file($to);

        /** @var \SimpleXMLElement $fileNode */
        $fileNode = $xml->file;
        $fileNode->attributes()->{'target-language'} = $locale;
        $fileNode->attributes()->{'original'} = $domain . '.en.xlf';

        unset($xml->file->body);

        $xmlDocument = new \DOMDocument('1.0', 'UTF-8');
        $xmlDocument->preserveWhiteSpace = false;
        $xmlDocument->formatOutput = true;
        $xmlDocument->loadXML($xml->asXML());

        $xpath = new \DOMXpath($xmlDocument);
        $xpath->registerNamespace('ns', $xmlDocument->documentElement->namespaceURI);

        $xmlContent = '';
        foreach ($translations as $id => $values) {
            $xmlContent .= sprintf(
                '<trans-unit id="%s" resname="%s"><source>%s</source><target>%s</target></trans-unit>',
                $id,
                $values['resname'],
                $values['source'],
                $values['target']
            );
        }

        $fragment = $xmlDocument->createDocumentFragment();
        $fragment->appendXml('<body>' . $xmlContent . '</body>');

        /** @var \DOMElement $element */
        $element = $xpath->evaluate('/ns:xliff/ns:file')->item(0);
        $element->appendChild($fragment);

        file_put_contents($to, $xmlDocument->saveXML());
    }

    private function fixXlfFile(string $file): void
    {
        $xml = simplexml_load_file($file);
        if (isset($xml->file->header)) {
            unset($xml->file->header);
        }

        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $source = $unit->source;
            if (!isset($unit['resname'])) {
                $unit['resname'] = $source;
            }
            $unit['id'] = strtr(substr(base64_encode(hash('sha256', $source, true)), 0, 7), '/+', '._');
        }

        $xmlDocument = new \DOMDocument('1.0');
        $xmlDocument->preserveWhiteSpace = false;
        $xmlDocument->formatOutput = true;
        $xmlDocument->loadXML($xml->asXML());

        file_put_contents($file, $xmlDocument->saveXML());
    }

    private function removeKey(string $file, string $key): void
    {
        $xml = simplexml_load_file($file);

        /** @var \SimpleXMLElement $unit */
        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            if (!isset($unit['resname'])) {
                continue;
            }

            if ((string) $unit['resname'] === $key) {
                $dom = dom_import_simplexml($unit);
                $dom->parentNode->removeChild($dom);
                break;
            }
        }

        $xmlDocument = new \DOMDocument('1.0');
        $xmlDocument->preserveWhiteSpace = false;
        $xmlDocument->formatOutput = true;
        $xmlDocument->loadXML($xml->asXML());

        file_put_contents($file, $xmlDocument->saveXML());
    }
}
