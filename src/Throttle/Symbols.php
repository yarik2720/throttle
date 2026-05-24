<?php

namespace Throttle;

use Silex\Application;

class Symbols
{
    public function submit(Application $app)
    {
        $data = $app['request']->get('symbol_file');
        if ($data === null) {
            $data = $app['request']->getContent();
        }

        $app['redis']->hIncrBy('throttle:stats', 'symbols:submitted', 1);
        $app['redis']->hIncrBy('throttle:stats', 'symbols:submitted:bytes', strlen($data));

        $firstLineEnd = strpos($data, "\n");
        $firstLine = $firstLineEnd === false ? $data : substr($data, 0, $firstLineEnd);
        $firstLine = rtrim($firstLine, "\r\n");

        if (!preg_match('/^MODULE (?P<operatingsystem>[^ ]++) (?P<architecture>[^ ]++) (?P<id>[a-fA-F0-9]++) (?P<name>[^\\/\\\\\r\n]++)$/', $firstLine, $info)) {
            $app['monolog']->warning('Invalid symbol file: ' . $firstLine);
            $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:invalid', 1);

            return new \Symfony\Component\HttpFoundation\Response('Invalid symbol file', 400);
        }

        if ($info['operatingsystem'] === 'Linux') {
            $functions = 0;
            $offset = 0;
            $length = strlen($data);

            while ($offset < $length) {
                $lineEnd = strpos($data, "\n", $offset);
                if ($lineEnd === false) {
                    $lineEnd = $length;
                }
                $lineLength = $lineEnd - $offset;

                if ($lineLength >= 5 && substr_compare($data, 'STACK', $offset, 5) === 0 && ($lineLength === 5 || $data[$offset + 5] === ' ' || $data[$offset + 5] === "\r")) {
                    break;
                }

                if ($lineLength >= 5 && substr_compare($data, 'FUNC ', $offset, 5) === 0) {
                    $functions++;
                }

                $offset = $lineEnd + 1;
            }

            if ($functions === 0) {
                $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:no-functions', 1);
                return new \Symfony\Component\HttpFoundation\Response('Symbol file had no FUNC records, please update to Accelerator 2.4.3 or later', 400);
            }
        }

        $path = $app['root'] . '/symbols/public/' . $info['name'] . '/' . $info['id'];

        \Filesystem::createDirectory($path, 0755, true);

        $file = $info['name'];
        if (pathinfo($file, PATHINFO_EXTENSION) == 'pdb') {
            $file = substr($file, 0, -4);
        }

        $symbolPath = $path . '/' . $file . '.sym.gz';
        $symbolFile = gzopen($symbolPath, 'wb9');
        if ($symbolFile === false) {
            throw new \RuntimeException('Failed to open symbol file for writing: ' . $symbolPath);
        }

        gzwrite($symbolFile, $data);
        gzclose($symbolFile);

        $app['redis']->hIncrBy('throttle:stats', 'symbols:accepted', 1);

        return $app['twig']->render('submit-symbols.txt.twig', array(
            'module' => $info,
        ));
    }
}
