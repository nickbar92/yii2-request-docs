<?php

namespace wbarcovsky\yii2\request_docs\components;

use wbarcovsky\yii2\request_docs\models\apiDocJs\Tags\Api;
use wbarcovsky\yii2\request_docs\models\apiDocJs\Tags\ApiDefine;
use wbarcovsky\yii2\request_docs\models\apiDocJs\Tags\ApiDescription;
use wbarcovsky\yii2\request_docs\Module;
use Yii;
use wbarcovsky\yii2\request_docs\commands\UrlResolverController;
use wbarcovsky\yii2\request_docs\helpers\StructureHelper;
use wbarcovsky\yii2\request_docs\models\DocRequest;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use phpDocumentor\Reflection\DocBlockFactory;

class RequestDocs extends Component
{
    public $storeFolder = '@app/runtime/docs';

    public $excludeParams = [];

    public $autoLoadRequests = false;
    /** @var UrlResolverController */
    protected $command;
    /** @var Module */
    protected $module;

    /**
     * @var DocRequest[]
     */
    protected $requests = [];

    public function init()
    {
        parent::init();
        if ($this->autoLoadRequests) {
            $this->loadRequests();
        }
        $this->module = $this->getRequestDocsModule();
        $this->command = new UrlResolverController(UrlResolverController::class, $this->module);
    }


    /**
     * @return Module
     * @throws \Exception
     */
    public function getRequestDocsModule()
    {
        $id = '';
        foreach (Yii::$app->modules as $moduleId => $config) {
            if (is_array($config)) {
                if (isset($config['class'])
                    && $config['class'] == Module::class
                ) {
                    $id = $moduleId;
                    break;
                }
            } elseif (is_object($config)) {
                if (Module::class == get_class($config)) {
                    $id = $moduleId;
                    break;
                }
            }
        }
        if (empty($id)) {
            throw new \Exception('Модуль ' . Module::class . ' не найден (пропишите его в секцию modules основного конфига');
        }
        $module = Yii::$app->getModule($id);
        if (empty($module)) {
            throw new \Exception('Модуль ' . Module::class . ' не загружен');
        }
        return $module;
    }

    /**
     * Из $url найдет контроллер, действие и вытащит из DocBlock метода краткое и подробное описание.
     * @param string $url
     * @param string $httpMethod
     * @return array
     * @throws \ReflectionException
     */
    protected function getUrlInto($url, $httpMethod)
    {
        $result = [
            'summary' => '', // краткое описание
            'description' => '', // подробное описание
        ];
        list ($controllerClassName, $actionMethodName) = $this->command->runAction('route', [
            $this->module->getUniqueId(), // moduleId
            $url,
            $httpMethod,
        ]);
        $httpMethodUpper = mb_convert_case($httpMethod, MB_CASE_UPPER);

        $reflection = new \ReflectionMethod($controllerClassName, $actionMethodName);
        $docComment = $reflection->getDocComment();
        if ($docComment) {
            $docCommentList = self::getAllDocComment($controllerClassName, $actionMethodName);
            $factory = DocBlockFactory::createInstance([
                'api' => Api::class,
                'apiDefine' => ApiDefine::class,
                'apiDescription' => ApiDescription::class,
            ]);
            foreach ($docCommentList as $docComment) {
                $docBlock = $factory->create($docComment);
                /** @var BaseTag $tag */
                $apiTagList = $docBlock->getTagsByName('api');
                /** @var Api $apiTag */
                foreach ($apiTagList as $apiTag) {
                    if ($apiTag->getMethod() == $httpMethodUpper) {
                        $result['summary'] = $apiTag->getTitle();
                        $apiDescriptionTagList = $docBlock->getTagsByName('apiDescription');
                        if (isset($apiDescriptionTagList[0])) {
                            $result['description'] = $apiDescriptionTagList[0]->getText()->render();
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Вытаскивает все DocBlock комментарии для заданного метода $methodName, а не только самый первый (как это делает
     * \ReflectionMethod::getDocComment().
     * @param string $className Имя класса
     * @param string $methodName Имя метода
     * @return array
     * @throws \ReflectionException
     */
    private static function getAllDocComment($className, $methodName)
    {
        $result = [];
        $class = new \ReflectionClass($className);
        $tokenList = token_get_all(file_get_contents($class->getFileName()));
        // Ключи элементов в которых встречается $methodName
        $methodNameIndexList = array_keys(array_filter($tokenList, function ($value) use ($methodName) {
            return isset($value[1]) && $value[1] == $methodName;
        }));
        foreach ($methodNameIndexList as $index) {
            $functionIndex = $index - 2;
            // В массиве токенов находим определение функции
            if (isset($tokenList[$functionIndex])
                && $tokenList[$functionIndex][0] === T_FUNCTION
            ) {
                for ($i = $functionIndex; $i > 0; $i--) {
                    $token = $tokenList[$i];
                    if (is_string($token)) {
                        // Если это...
                        if ($token == '}'    // ...предыдущий метод
                            || $token == '{' // ...начало описание класса
                            || $token == ';' // ...переменная, константа
                        ) {
                            break 2; // все DocBlock методы получены
                        }
                    } else {
                        if ($token[0] === T_DOC_COMMENT) {
                            $result[] = $token[1];
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * @param string $method
     * @param string $url
     * @param string $title
     * @param array $params
     * @param array $result
     * @return DocRequest
     */
    public function addRequest($method, $url, $title = '', $params = [], $result = [])
    {
        $urlInfo = $this->getUrlInto($url, $method);
        $request = new DocRequest([
            'url' => $url,
            'method' => $method,
            'title' => empty($title) ? $urlInfo['summary'] : $title,
            'description' => $urlInfo['description'],
        ]);
        $hash = $request->getMethodHash();
        if (isset($this->requests[$hash])) {
            $this->requests[$hash]->addParams($params);
            $this->requests[$hash]->addResult($result);
        } else {
            $request->addResult($result);
            $request->addParams($params);
            $this->requests[$hash] = $request;
        }
        return $this->requests[$hash];
    }

    public function storeRequests()
    {
        $path = Yii::getAlias($this->storeFolder);
        if (!is_writeable($path)) {
            throw new \Exception("Path '{$path}' is not writeable'");
        }
        foreach ($this->requests as $request) {
            $mergeParams = [];
            if (!empty($request->hash) && $request->getDataHash() === $request->hash) {
                continue;
            }
            $oldRequest = $this->loadRequestByHash($request->getMethodHash());
            if ($oldRequest && $oldRequest->getDataHash() === $request->getDataHash()) {
                continue;
            }
            foreach ($request->getParams() as $param) {
                $mergeParams = ArrayHelper::merge($mergeParams, $param);
            }
            $mergeResult = [];
            foreach ($request->getResult() as $result) {
                $mergeResult = ArrayHelper::merge($mergeResult, $result);
            }
            $shortInfo = [
                'method' => $request->method,
                'url' => $request->url,
                'title' => $request->title,
                'params' => StructureHelper::getStructure($mergeParams),
                'result' => StructureHelper::getStructure($mergeResult),
            ];
            // Store short info in .json file
            $shortPath = $this->getShortInfoPath($request);
            $this->createDir(dirname($shortPath));
            file_put_contents($shortPath, StructureHelper::jsonPrettyPrint($shortInfo, false));

            // Store full info in zip archive
            $request->hash = $request->getDataHash();
            $fillInfoPath = $this->getFullInfoPath($request);
            $this->createDir(dirname($fillInfoPath));
            $zip = new \ZipArchive();
            $zip->open($fillInfoPath, \ZipArchive::CREATE);
            $zip->addFromString('data.json', json_encode($request->toArray()));
            $zip->close();
        }
    }

    /**
     * @param DocRequest $request
     * @return string
     */
    protected function getShortInfoPath($request)
    {
        return "{$this->folder()}/{$request->method}__{$this->getUrlPath($request->url)}.{$request->getMethodHash()}.json";
    }

    /**
     * @param DocRequest $request
     * @return string
     * @internal param bool $zip
     */
    protected function getFullInfoPath($request)
    {
        return "{$this->folder(true)}/{$request->method}__{$this->getUrlPath($request->url)}.{$request->getMethodHash()}.zip";
    }

    protected function folder($fullInfo = false)
    {
        return Yii::getAlias($this->storeFolder) . ($fullInfo ? '/full_info' : '');
    }

    protected function getUrlPath($url)
    {
        return str_replace(':id', 'id', str_replace('/', '-', $url));
    }

    public function loadRequests()
    {
        $this->requests = [];
        $files = glob($this->folder(true) . '/*');
        foreach ($files as $file) {
            if (is_file($file) && $file) {
                $request = new DocRequest();
                $data = json_decode(file_get_contents("zip://{$file}#data.json"), true);
                if (isset($data['url'])) {
                    $request->setAttributes($data, false);
                    $this->requests[] = $request;
                }
            }
        }
    }

    /**
     * @param $hash
     * @return null|DocRequest
     */
    public function loadRequestByHash($hash)
    {
        $files = glob($this->folder(true) . '/*');
        foreach ($files as $file) {
            if (is_file($file) && $file) {
                $array = explode('.', $file);
                $hashFile = $array[count($array) - 2];
                if ($hashFile === $hash) {
                    $request = new DocRequest();
                    $data = json_decode(file_get_contents("zip://{$file}#data.json"), true);
                    $request->setAttributes($data, false);
                    return $request;
                }
            }
        }
        return null;
    }

    public function loadShortInfo()
    {
        $result = [];
        $files = glob($this->folder() . '/*');
        foreach ($files as $file) {
            if (is_file($file) && $file) {
                $data = json_decode(file_get_contents($file), true);
                $array = explode('.', $file);
                $hash = $array[count($array) - 2];
                if (isset($data['url']) && !empty($hash)) {
                    $result[$hash] = $data;
                    $result[$hash]['hash'] = $hash;
                }
            }
        }
        return $result;
    }

    public function getRequests()
    {
        return $this->requests;
    }

    protected function createDir($folder)
    {
        if (!file_exists(dirname($folder))) {
            self::createDir(dirname($folder));
        }
        if (file_exists($folder)) {
            return;
        }
        mkdir($folder);
        chmod($folder, 0777);
    }

}
