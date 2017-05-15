<?php

use Symfony\Component\Yaml\Yaml;

class CollectTranslationsTask extends BuildTask {
 
    protected $title = 'Collect Translations Task';
 
    protected $description = '
        Collects translations from template and code files. Following GET arguments are supported:<br>
        <table>
            <tr>
                <th style="border-bottom: 1px solid black;">GET</th>
                <th style="border-bottom: 1px solid black;">Value</th>
                <th style="border-bottom: 1px solid black;">Description</th>
                <th style="border-bottom: 1px solid black;">Example</th>
            </tr>
            <tr>
                <td><b>verbose</b></td>
                <td>1<br>0</td>
                <td>Show which files are being processed and other debug info</td>
                <td>verbose=1</td>
            </tr>
            <tr>
                <td><b>compare</b></td>
                <td>[lang]</td>
                <td>Compare to an existing language file (e.g. "de")</td>
                <td>compare=de</td>
            </tr>
        </table>';

    protected $enabled = true;
 
    function run($request) {
        $verbose = $request->getVar("verbose");

        $arr = array();
        $arr = array_replace_recursive($arr, self::processDir("/themes/", $verbose));
        $arr = array_replace_recursive($arr, self::processDir("/mysite/", $verbose));

        self::ksortRecursive($arr);

        $compare = $request->getVar("compare");
        if ($compare) {
            $new = array($compare => $arr);
            $orig = Yaml::parse(file_get_contents(Director::baseFolder() . "/mysite/lang/$compare.yml"));

            self::compareArrays($orig, $new);

            self::ksortRecursive($orig);

            echo "<pre>" . htmlentities(Yaml::dump($orig, 10, 2)) . "</pre>";
        } else {
            $locale = mb_substr(i18n::get_locale(), 0, 2);
            $orig = array($locale => $arr);

            echo "<pre>" . htmlentities(Yaml::dump($orig, 10, 2)) . "</pre>";
        }
    }

    private static function ksortRecursive(&$array, $sort_flags = SORT_REGULAR) {
        if (!is_array($array)) return false;
        ksort($array, $sort_flags);

        foreach ($array as &$arr) {
            self::ksortRecursive($arr, $sort_flags);
        }

        return true;
    }

    private static function processDir($dir, $verbose) {
        $dir_iterator = new RecursiveDirectoryIterator(Director::baseFolder() . $dir);
        $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

        $arr = array();

        foreach ($iterator as $file) {
            $fileSplits = mb_split("\.", $file);
            $ending = end($fileSplits);

            // Skip all files except .ss and .php
            if ($ending !== "ss" && $ending !== "php") continue;

            // Skip this file
            if (realpath($file->getPathName()) === realpath(__FILE__)) {
                continue;
            }

            // Iterate all text lines in the file
            foreach (file($file) as $fli => $fl) {
                // Unset variable 'cause PHP
                unset($path);
                unset($value);

                // Check for the starting translation marker
                $pos = mb_strpos($fl, "<%t");
                if ($pos !== false) {
                    // Extract the translation part, and split it into key and default value
                    $data = substr($fl, $pos + 4, mb_strpos($fl, "%>", $pos + 4) - $pos - 5);
                    $splits = array_values(array_filter(mb_split(" ", $data)));
                    $path = array_values(array_filter(mb_split("\.", $splits[0])));
                    array_splice($splits, 0, 1);
                    $value = mb_ereg_replace("\"", "", implode(" ", $splits));
                } else {
                    // Check for translations using the PHP syntax
                    $pos = mb_strpos($fl, "_t(");
                    if ($pos !== false) {
                        $keyEndPos = mb_strpos($fl, ",", $pos + 3);
                        $key = mb_substr($fl, $pos + 3, $keyEndPos - $pos - 3);
                        $endPos = mb_strpos($fl, ",", $keyEndPos + 1);
                        if ($endPos === false) $endPos = mb_strpos($fl, ")", $keyEndPos + 1);
                        $value = mb_substr($fl, $keyEndPos + 1, $endPos - $keyEndPos - 1);

                        $key = mb_ereg_replace("\"", "", $key);
                        $value = mb_ereg_replace("\"", "", $value);

                        if (mb_strpos($key, "$") !== false) {
                            var_dump(array(
                                "file" => $file->getPathName(),
                                "line" => trim(mb_ereg_replace("\n", "", $fl)),
                                "lineNr" => $fli + 1,
                                "info" => "Skipping because it might contain a variable",
                            ));
                            continue;
                        } else {
                            $path = array_values(array_filter(mb_split("\.", $key)));
                        }
                    }
                }

                if (isset($path) && isset($value)) {
                    if ($verbose) {
                       var_dump(array(
                            "file" => $file->getPathName(),
                            "line" => trim(mb_ereg_replace("\n", "", $fl)),
                            "line" => $fl,
                            "lineNr" => $fli + 1,
                            "path" => $path,
                            "value" => $value,
                        ));
                    }

                    // Use the key as the path into the translations array
                    $temp = &$arr;
                    foreach ($path as $key) {
                        $k = trim($key);
                        $temp = &$temp[$k];
                    }
                    $temp = $value;
                }
            }
        }

        return $arr;
    }

    private static function compareArrays(&$orig, &$new) {
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $orig)) {
                self::setValue($key, $orig, $new);
            } else if (is_array($value)) {
                self::compareArrays($orig[$key], $value);
            }
        }
    }
    private static function setValue($key, &$orig, &$new) {
        if (is_array($new[$key])) {
            $orig[$key] = array();
            foreach ($new[$key] as $subKey => $value) {
                self::setValue($subKey, $orig[$key], $new[$key]);
            }
        } else {
            $orig[$key] = "[*] " . $new[$key];
        }
    }
}
