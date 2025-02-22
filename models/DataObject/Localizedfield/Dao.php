<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @category   Pimcore
 * @package    Object
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Model\DataObject\Localizedfield;

use Pimcore\Db;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data\CustomResourcePersistingInterface;
use Pimcore\Model\DataObject\ClassDefinition\Data\QueryResourcePersistenceAwareInterface;
use Pimcore\Model\DataObject\ClassDefinition\Data\ResourcePersistenceAwareInterface;
use Pimcore\Tool;

/**
 * @property \Pimcore\Model\DataObject\Localizedfield $model
 */
class Dao extends Model\Dao\AbstractDao
{
    use DataObject\ClassDefinition\Helper\Dao;

    /**
     * @var null
     */
    protected $tableDefinitions = null;

    /**
     * @return string
     */
    public function getTableName()
    {
        $context = $this->model->getContext();
        if ($context) {
            $containerType = $context['containerType'];
            if ($containerType == 'fieldcollection') {
                $containerKey = $context['containerKey'];

                return 'object_collection_'.$containerKey.'_localized_'.$this->model->getClass()->getId();
            } elseif ($containerType == 'objectbrick') {
                $containerKey = $context['containerKey'];

                return 'object_brick_localized_'.$containerKey.'_'.$this->model->getClass()->getId();
            }
        }

        return 'object_localized_data_'.$this->model->getClass()->getId();
    }

    /**
     * @return string
     */
    public function getQueryTableName()
    {
        $context = $this->model->getContext();
        if ($context) {
            $containerType = $context['containerType'];
            if ($containerType == 'objectbrick') {
                $containerKey = $context['containerKey'];

                return 'object_brick_localized_query_'.$containerKey.'_'.$this->model->getClass()->getId();
            }
        }

        return 'object_localized_query_'.$this->model->getClass()->getId();
    }

    public function save($params = [])
    {
        $context = $this->model->getContext();

        // if inside a field collection a delete is not necessary as the fieldcollection deletes all entries anyway
        // see Pimcore\Model\DataObject\Fieldcollection\Dao::delete

        if ((isset($params['newParent']) && $params['newParent']) || DataObject\AbstractObject::isDirtyDetectionDisabled() || $this->model->hasDirtyLanguages(
            ) || $context['containerType'] == 'fieldcollection') {
            $this->delete(false);
        }

        $object = $this->model->getObject();
        $validLanguages = Tool::getValidLanguages();

        if ($context && $context['containerType'] == 'fieldcollection') {
            $containerKey = $context['containerKey'];
            $container = DataObject\Fieldcollection\Definition::getByKey($containerKey);
        } elseif ($context && $context['containerType'] == 'objectbrick') {
            $containerKey = $context['containerKey'];
            $container = DataObject\Objectbrick\Definition::getByKey($containerKey);
        } else {
            $container = $this->model->getClass();
        }

        $fieldDefinitions = $container->getFielddefinition('localizedfields')->getFielddefinitions(
            ['suppressEnrichment' => true]
        );

        /**
         * We temporary enable the runtime cache so we don't have to calculate the tree for each language
         * which is a great performance gain if you have a lot of languages
         */
        DataObject\Concrete\Dao\InheritanceHelper::setUseRuntimeCache(true);
        foreach ($validLanguages as $language) {
            if ((!isset($params['newParent']) || !$params['newParent']) && isset($params['isUpdate']) && $params['isUpdate'] && !$this->model->isLanguageDirty(
                    $language
                )) {
                continue;
            }
            $inheritedValues = DataObject\AbstractObject::doGetInheritedValues();
            DataObject\AbstractObject::setGetInheritedValues(false);

            $insertData = [
                'ooo_id' => $this->model->getObject()->getId(),
                'language' => $language,
            ];

            if ($container instanceof DataObject\Objectbrick\Definition) {
                $insertData['fieldname'] = $context['fieldname'];
            } elseif ($container instanceof DataObject\Fieldcollection\Definition) {
                $insertData['fieldname'] = $context['fieldname'];
                $insertData['index'] = $context['index'];
            }

            foreach ($fieldDefinitions as $fd) {
                if ($fd instanceof CustomResourcePersistingInterface) {
                    // for fieldtypes which have their own save algorithm eg. relational data types, ...
                    $context = $this->model->getContext() ? $this->model->getContext() : [];
                    if ($context['containerType'] == 'fieldcollection' || $context['containerType'] == 'objectbrick') {
                        $context['subContainerType'] = 'localizedfield';
                    }

                    $childParams = [
                        'context' => $context,
                        'language' => $language,
                    ];

                    if ($fd instanceof DataObject\ClassDefinition\Data\Relations\AbstractRelations) {
                        if ((isset($params['saveRelationalData'])
                                && $params['saveRelationalData']['saveLocalizedRelations']
                                && $container instanceof DataObject\Fieldcollection\Definition
                                && !$container instanceof DataObject\Objectbrick\Definition
                            )
                            || ($this->model->isLanguageDirty($language) || $params['saveRelationalData']['saveLocalizedRelations'])) {
                            $fd->save($this->model, $childParams);
                        }
                    } else {
                        $fd->save($this->model, $childParams);
                    }
                }
                if ($fd instanceof ResourcePersistenceAwareInterface) {
                    if (is_array($fd->getColumnType())) {
                        $insertDataArray = $fd->getDataForResource(
                            $this->model->getLocalizedValue($fd->getName(), $language, true),
                            $object,
                            $this->getFieldDefinitionParams($fd->getName(), $language)
                        );
                        $insertData = array_merge($insertData, $insertDataArray);
                    } else {
                        $insertData[$fd->getName()] = $fd->getDataForResource(
                            $this->model->getLocalizedValue($fd->getName(), $language, true),
                            $object,
                            $this->getFieldDefinitionParams($fd->getName(), $language)
                        );
                    }
                }
            }

            $storeTable = $this->getTableName();
            $queryTable = $this->getQueryTableName().'_'.$language;

            try {
                if ((isset($params['newParent']) && $params['newParent']) || !isset($params['isUpdate']) || !$params['isUpdate'] || $this->model->isLanguageDirty(
                        $language
                    )) {
                    $this->db->insertOrUpdate($storeTable, $insertData);
                }
            } catch (\Exception $e) {
                // if the table doesn't exist -> create it! deferred creation for object bricks ...
                if (strpos($e->getMessage(), 'exist')) {
                    $this->db->rollBack();
                    $this->createUpdateTable();
                    throw new \Exception('missing table created, start next run ... ;-)');
                }
                throw $e;
            }

            if ($container instanceof DataObject\ClassDefinition || $container instanceof DataObject\Objectbrick\Definition) {
                // query table
                $data = [];
                $data['ooo_id'] = $this->model->getObject()->getId();
                $data['language'] = $language;

                $this->inheritanceHelper = new DataObject\Concrete\Dao\InheritanceHelper(
                    $object->getClassId(),
                    'ooo_id',
                    $storeTable,
                    $queryTable
                );
                $this->inheritanceHelper->resetFieldsToCheck();
                $sql = 'SELECT * FROM '.$queryTable.' WHERE ooo_id = '.$object->getId(
                    )." AND language = '".$language."'";

                $oldData = [];
                try {
                    $oldData = $this->db->fetchRow($sql);
                } catch (\Exception $e) {
                    // if the table doesn't exist -> create it!
                    if (strpos($e->getMessage(), 'exist')) {

                        // the following is to ensure consistent data and atomic transactions, while having the flexibility
                        // to add new languages on the fly without saving all classes having localized fields

                        // first we need to roll back all modifications, because otherwise they would be implicitly committed
                        // by the following DDL
                        $this->db->rollBack();

                        // this creates the missing table
                        $this->createUpdateTable();

                        // at this point we throw an exception so that the transaction gets repeated in DataObject::save()
                        throw new \Exception('missing table created, start next run ... ;-)');
                    }
                }

                // get fields which shouldn't be updated
                $untouchable = [];

                // @TODO: currently we do not support lazyloading in localized fields

                $inheritanceEnabled = $object->getClass()->getAllowInherit();
                $parentData = null;
                if ($inheritanceEnabled) {
                    // get the next suitable parent for inheritance
                    $parentForInheritance = $object->getNextParentForInheritance();
                    if ($parentForInheritance) {
                        // we don't use the getter (built in functionality to get inherited values) because we need to avoid race conditions
                        // we cannot DataObject\AbstractObject::setGetInheritedValues(true); and then $this->model->getLocalizedValue($key, $language)
                        // so we select the data from the parent object using FOR UPDATE, which causes a lock on this row
                        // so the data of the parent cannot be changed while this transaction is on progress
                        $parentData = $this->db->fetchRow(
                            'SELECT * FROM '.$queryTable.' WHERE ooo_id = ? AND language = ? FOR UPDATE',
                            [$parentForInheritance->getId(), $language]
                        );
                    }
                }

                foreach ($fieldDefinitions as $fd) {
                    if ($fd instanceof QueryResourcePersistenceAwareInterface) {
                        $key = $fd->getName();

                        // exclude untouchables if value is not an array - this means data has not been loaded
                        if (!in_array($key, $untouchable)) {
                            $localizedValue = $this->model->getLocalizedValue($key, $language);
                            $insertData = $fd->getDataForQueryResource(
                                $localizedValue,
                                $object,
                                $this->getFieldDefinitionParams($key, $language)
                            );
                            $isEmpty = $fd->isEmpty($localizedValue);

                            if (is_array($insertData)) {
                                $columnNames = array_keys($insertData);
                                $data = array_merge($data, $insertData);
                            } else {
                                $columnNames = [$key];
                                $data[$key] = $insertData;
                            }

                            // if the current value is empty and we have data from the parent, we just use it
                            if ($isEmpty && $parentData) {
                                foreach ($columnNames as $columnName) {
                                    if (array_key_exists($columnName, $parentData)) {
                                        $data[$columnName] = $parentData[$columnName];
                                        if (is_array($insertData)) {
                                            $insertData[$columnName] = $parentData[$columnName];
                                        } else {
                                            $insertData = $parentData[$columnName];
                                        }
                                    }
                                }
                            }

                            if ($inheritanceEnabled && $fd->getFieldType() != 'calculatedValue') {
                                //get changed fields for inheritance
                                if ($fd->isRelationType()) {
                                    if (is_array($insertData)) {
                                        $doInsert = false;
                                        foreach ($insertData as $insertDataKey => $insertDataValue) {
                                            if ($isEmpty && $oldData[$insertDataKey] == $parentData[$insertDataKey]) {
                                                // do nothing, ... value is still empty and parent data is equal to current data in query table
                                            } elseif ($oldData[$insertDataKey] != $insertDataValue) {
                                                $doInsert = true;
                                                break;
                                            }
                                        }

                                        if ($doInsert) {
                                            $this->inheritanceHelper->addRelationToCheck(
                                                $key,
                                                $fd,
                                                array_keys($insertData)
                                            );
                                        }
                                    } else {
                                        if ($isEmpty && $oldData[$key] == $parentData[$key]) {
                                            // do nothing, ... value is still empty and parent data is equal to current data in query table
                                        } elseif ($oldData[$key] != $insertData) {
                                            $this->inheritanceHelper->addRelationToCheck($key, $fd);
                                        }
                                    }
                                } else {
                                    if (is_array($insertData)) {
                                        foreach ($insertData as $insertDataKey => $insertDataValue) {
                                            if ($isEmpty && $oldData[$insertDataKey] == $parentData[$insertDataKey]) {
                                                // do nothing, ... value is still empty and parent data is equal to current data in query table
                                            } elseif ($oldData[$insertDataKey] != $insertDataValue) {
                                                $this->inheritanceHelper->addFieldToCheck($insertDataKey, $fd);
                                            }
                                        }
                                    } else {
                                        if ($isEmpty && $oldData[$key] == $parentData[$key]) {
                                            // do nothing, ... value is still empty and parent data is equal to current data in query table
                                        } elseif ($oldData[$key] != $insertData) {
                                            // data changed, do check and update
                                            $this->inheritanceHelper->addFieldToCheck($key, $fd);
                                        }
                                    }
                                }
                            }
                        } else {
                            Logger::debug(
                                'Excluding untouchable query value for object [ '.$this->model->getId(
                                )." ]  key [ $key ] because it has not been loaded"
                            );
                        }
                    }
                }

                $queryTable = $this->getQueryTableName().'_'.$language;
                $this->db->insertOrUpdate($queryTable, $data);
                if ($inheritanceEnabled) {
                    $this->inheritanceHelper->doUpdate($object->getId(), true);
                }
                $this->inheritanceHelper->resetFieldsToCheck();
            }

            DataObject\AbstractObject::setGetInheritedValues($inheritedValues);
        } // foreach language
        DataObject\Concrete\Dao\InheritanceHelper::setUseRuntimeCache(false);
        DataObject\Concrete\Dao\InheritanceHelper::clearRuntimeCache();
    }

    /**
     * @param bool $deleteQuery
     */
    public function delete($deleteQuery = true, $isUpdate = true)
    {
        if ($isUpdate && !DataObject\AbstractObject::isDirtyDetectionDisabled() && !$this->model->hasDirtyFields()) {
            return;
        }
        $object = $this->model->getObject();

        try {
            $context = $this->model->getContext();
            if ($context && ($context['containerType'] == 'fieldcollection' || $context['containerType'] == 'objectbrick')) {
                $containerKey = $context['containerKey'];
                if ($context['containerType'] == 'fieldcollection') {
                    $container = DataObject\Fieldcollection\Definition::getByKey($containerKey);
                } else {
                    $container = DataObject\Objectbrick\Definition::getByKey($containerKey);
                }
            } else {
                $container = $object->getClass();
            }
            if ($deleteQuery) {
                $id = $object->getId();
                $tablename = $this->getTableName();
                if ($context && $context['containerType'] == 'objectbrick') {
                    $this->db->delete($tablename, ['ooo_id' => $id, 'fieldname' => $context['fieldname']]);
                } else {
                    $this->db->delete($tablename, ['ooo_id' => $id]);
                }

                if (!$container instanceof DataObject\Fieldcollection\Definition || $container instanceof DataObject\Objectbrick\Definition) {
                    $validLanguages = Tool::getValidLanguages();
                    foreach ($validLanguages as $language) {
                        $queryTable = $this->getQueryTableName().'_'.$language;
                        $this->db->delete($queryTable, ['ooo_id' => $id]);
                    }
                }
            }

            $fieldDefinition = $container->getFieldDefinition('localizedfields', ['suppressEnrichment' => true]);
            $childDefinitions = $fieldDefinition->getFielddefinitions(['suppressEnrichment' => true]);

            if (is_array($childDefinitions)) {
                foreach ($childDefinitions as $fd) {
                    if ($fd instanceof CustomResourcePersistingInterface) {
                        $params = [];
                        $params['context'] = $this->model->getContext() ? $this->model->getContext() : [];
                        if (isset($params['context']['containerType']) && ($params['context']['containerType'] == 'fieldcollection' || $params['context']['containerType'] == 'objectbrick')) {
                            $params['context']['subContainerType'] = 'localizedfield';
                        }

                        $fd->delete($object, $params);
                    }
                }
            }
        } catch (\Exception $e) {
            Logger::error($e);
            $this->createUpdateTable();
        }

        // remove relations
        if (!DataObject\AbstractObject::isDirtyDetectionDisabled()) {
            if (!$this->model->hasDirtyFields()) {
                return;
            }
        }

        $db = Db::get();

        if ($this->model->allLanguagesAreDirty() ||
            ($container instanceof DataObject\Fieldcollection\Definition
            && !$container instanceof DataObject\Objectbrick\Definition)
            ) {
            $dirtyLanguageCondition = '';
        } elseif ($this->model->hasDirtyLanguages()) {
            $languageList = [];
            if (is_array($this->model->getDirtyLanguages())) {
                foreach ($this->model->getDirtyLanguages() as $language => $flag) {
                    if ($flag) {
                        $languageList[] = $db->quote($language);
                    }
                }
            }

            $dirtyLanguageCondition = ' AND position IN('.implode(',', $languageList).')';
        }

        if ($container instanceof DataObject\Objectbrick\Definition || $container instanceof DataObject\Fieldcollection\Definition) {
            $objectId = $object->getId();
            $index = $context['index'];
            $containerName = $context['fieldname'];
            if (!$context['containerType']) {
                throw new \Exception('no container type set');
            }

            $sql = $this->db->quoteInto('src_id = ?', $objectId)." AND ownertype = 'localizedfield' AND "
                .$this->db->quoteInto(
                    'ownername LIKE ?',
                    '/'.$context['containerType'].'~'.$containerName.'/'.$index.'/%'
                ).$dirtyLanguageCondition;

            $this->db->deleteWhere('object_relations_'.$object->getClassId(), $sql);
        } else {
            $sql = 'ownertype = "localizedfield" AND ownername = "localizedfield" and src_id = '.$this->model->getObject(
                )->getId().$dirtyLanguageCondition;
            $this->db->deleteWhere('object_relations_'.$this->model->getObject()->getClassId(), $sql);
        }
    }

    /**
     * @param $object
     * @param array $params
     */
    public function load($object, $params = [])
    {
        $validLanguages = Tool::getValidLanguages();
        foreach ($validLanguages as &$language) {
            $language = $this->db->quote($language);
        }

        $context = $this->model->getContext();
        if ($context && $context['containerType'] == 'fieldcollection') {
            $containerKey = $context['containerKey'];
            $index = $context['index'];
            $fieldname = $context['fieldname'];

            $container = DataObject\Fieldcollection\Definition::getByKey($containerKey);

            $data = $this->db->fetchAll(
                'SELECT * FROM '.$this->getTableName()
                .' WHERE ooo_id = ? AND language IN ('.implode(
                    ',',
                    $validLanguages
                ).') AND `fieldname` = ? AND `index` = ?',
                [
                    $this->model->getObject()->getId(),
                    $fieldname,
                    $index,
                ]
            );
        } elseif ($context && $context['containerType'] == 'objectbrick') {
            $containerKey = $context['containerKey'];
            $container = DataObject\Objectbrick\Definition::getByKey($containerKey);
            $fieldname = $context['fieldname'];

            $data = $this->db->fetchAll(
                'SELECT * FROM '.$this->getTableName()
                .' WHERE ooo_id = ? AND language IN ('.implode(',', $validLanguages).') AND `fieldname` = ?',
                [
                    $this->model->getObject()->getId(),
                    $fieldname,
                ]
            );
        } else {
            $container = $this->model->getClass();
            $data = $this->db->fetchAll(
                'SELECT * FROM '.$this->getTableName().' WHERE ooo_id = ? AND language IN ('.implode(
                    ',',
                    $validLanguages
                ).')',
                [$this->model->getObject()->getId()]
            );
        }

        foreach ($data as $row) {
            foreach ($container->getFielddefinition('localizedfields')->getFielddefinitions(
                ['object' => $object, 'suppressEnrichment' => true]
            ) as $key => $fd) {
                if ($fd) {
                    if ($fd instanceof CustomResourcePersistingInterface) {
                        // datafield has it's own loader
                        $params['language'] = $row['language'];
                        $params['object'] = $object;
                        if (!isset($params['context'])) {
                            $params['context'] = [];
                        }
                        $params['context']['object'] = $object;

                        if ($fd instanceof  DataObject\ClassDefinition\Data\Relations\AbstractRelations && !DataObject\Concrete::isLazyLoadingDisabled() && $fd->getLazyLoading()) {
                            $lazyKey = $fd->getName() . DataObject\LazyLoadedFieldsInterface::LAZY_KEY_SEPARATOR . $row['language'];
                        } else {
                            $value = $fd->load($this->model, $params);
                            if ($value === 0 || !empty($value)) {
                                $this->model->setLocalizedValue($key, $value, $row['language'], false);
                            }
                        }
                    }
                    if ($fd instanceof ResourcePersistenceAwareInterface) {
                        if (is_array($fd->getColumnType())) {
                            $multidata = [];
                            foreach ($fd->getColumnType() as $fkey => $fvalue) {
                                $multidata[$key.'__'.$fkey] = $row[$key.'__'.$fkey];
                            }
                            $value = $fd->getDataFromResource($multidata, null, $this->getFieldDefinitionParams($key, $row['language']));
                            $this->model->setLocalizedValue($key, $value, $row['language'], false);
                        } else {
                            $value = $fd->getDataFromResource($row[$key], null, $this->getFieldDefinitionParams($key, $row['language']));
                            $this->model->setLocalizedValue($key, $value, $row['language'], false);
                        }
                    }
                }
            }
        }
    }

    public function createLocalizedViews()
    {

        // init
        $languages = Tool::getValidLanguages();
        $defaultTable = 'object_query_'.$this->model->getClass()->getId();

        $db = $this->db;

        /**
         * macro for creating ifnull statement
         *
         * @param string $field
         * @param array $languages
         *
         * @return string
         */
        $getFallbackValue = function ($field, array $languages) use (&$getFallbackValue, $db) {

            // init
            $lang = array_shift($languages);

            // get fallback for current language
            $fallback = count($languages) > 0
                ? $getFallbackValue($field, $languages)
                : 'null';

            // create query
            $sql = sprintf(
                'IF(`%s`.`%s` IS NULL OR `%s`.`%s` = "", %s, `%s`.`%s`)',
                $lang,
                $field,
                $lang,
                $field,
                $fallback,
                $lang,
                $field
            );

            return $fallback !== 'null'
                ? $sql
                : $db->quoteIdentifier($lang).'.'.$db->quoteIdentifier($field);
        };

        foreach ($languages as $language) {
            try {
                $tablename = $this->getQueryTableName().'_'.$language;

                // get available columns
                $viewColumns = array_merge(
                    $this->db->fetchAll('SHOW COLUMNS FROM `'.$defaultTable.'`'),
                    $this->db->fetchAll('SHOW COLUMNS FROM `objects`')
                );
                $localizedColumns = $this->db->fetchAll('SHOW COLUMNS FROM `'.$tablename.'`');

                // get view fields
                $viewFields = [];
                foreach ($viewColumns as $row) {
                    $viewFields[] = $this->db->quoteIdentifier($row['Field']);
                }

                // create fallback select
                $localizedFields = [];
                $fallbackLanguages = array_unique(Tool::getFallbackLanguagesFor($language));
                array_unshift($fallbackLanguages, $language);
                foreach ($localizedColumns as $row) {
                    if ($row['Field'] == 'language' || $row['Field'] == 'ooo_id') {
                        $localizedFields[] = $db->quoteIdentifier($language).'.'.$db->quoteIdentifier($row['Field']);
                    } else {
                        $localizedFields[] = $getFallbackValue($row['Field'], $fallbackLanguages).sprintf(
                                ' as "%s"',
                                $row['Field']
                            );
                    }
                }

                // create view select fields
                $selectViewFields = implode(',', array_merge($viewFields, $localizedFields));

                // create view
                $viewQuery = <<<QUERY
CREATE OR REPLACE VIEW `object_localized_{$this->model->getClass()->getId()}_{$language}` AS

SELECT {$selectViewFields}
FROM `{$defaultTable}`
    JOIN `objects`
        ON (`objects`.`o_id` = `{$defaultTable}`.`oo_id`)
QUERY;

                // join fallback languages
                foreach ($fallbackLanguages as $lang) {
                    $viewQuery .= <<<QUERY
LEFT JOIN {$this->getQueryTableName()}_{$lang} as `{$lang}`
    ON( 1
        AND {$defaultTable}.oo_id = {$lang}.ooo_id
    )
QUERY;
                }

                // execute
                $this->db->query($viewQuery);
            } catch (\Exception $e) {
                Logger::error($e);
            }
        }
    }

    public function createUpdateTable($params = [])
    {
        $table = $this->getTableName();

        $context = $this->model->getContext();
        if ($context && $context['containerType'] == 'fieldcollection' || $context['containerType'] == 'objectbrick') {
            $this->db->query(
                'CREATE TABLE IF NOT EXISTS `'.$table."` (
              `ooo_id` int(11) NOT NULL default '0',
              `index` INT(11) NOT NULL DEFAULT '0',
              `fieldname` VARCHAR(190) NOT NULL DEFAULT '',
              `language` varchar(10) NOT NULL DEFAULT '',
              PRIMARY KEY (`ooo_id`, `language`, `index`, `fieldname`),
              INDEX `ooo_id` (`ooo_id`),
              INDEX `index` (`index`),
              INDEX `fieldname` (`fieldname`),
              INDEX `language` (`language`)
            ) DEFAULT CHARSET=utf8mb4;"
            );
        } else {
            $this->db->query(
                'CREATE TABLE IF NOT EXISTS `'.$table."` (
              `ooo_id` int(11) NOT NULL default '0',
              `language` varchar(10) NOT NULL DEFAULT '',
              PRIMARY KEY (`ooo_id`,`language`),
              INDEX `ooo_id` (`ooo_id`),
              INDEX `language` (`language`)
            ) DEFAULT CHARSET=utf8mb4;"
            );
        }

        $this->handleEncryption($this->model->getClass(), [$table]);

        $existingColumns = $this->getValidTableColumns($table, false); // no caching of table definition
        $columnsToRemove = $existingColumns;

        DataObject\ClassDefinition\Service::updateTableDefinitions($this->tableDefinitions, ([$table]));

        if ($context && ($context['containerType'] == 'fieldcollection' || $context['containerType'] == 'objectbrick')) {
            $protectedColumns = ['ooo_id', 'language', 'index', 'fieldname'];
            $containerKey = $context['containerKey'];
            if ($context['containerType'] == 'fieldcollection') {
                $container = DataObject\Fieldcollection\Definition::getByKey($containerKey);
            } else {
                $container = DataObject\Objectbrick\Definition::getByKey($containerKey);
            }
        } else {
            $protectedColumns = ['ooo_id', 'language'];
            $container = $this->model->getClass();
        }

        $localizedFieldDefinition = $container->getFielddefinition('localizedfields', ['suppressEnrichment' => true]);
        foreach ($localizedFieldDefinition->getFielddefinitions(['suppressEnrichment' => true]) as $value) {
            if ($value instanceof ResourcePersistenceAwareInterface || method_exists($value, 'getDataForResource')) {
                if ($value->getColumnType()) {
                    $key = $value->getName();

                    if (is_array($value->getColumnType())) {
                        // if a datafield requires more than one field
                        foreach ($value->getColumnType() as $fkey => $fvalue) {
                            $this->addModifyColumn($table, $key . '__' . $fkey, $fvalue, '', 'NULL');
                            $protectedColumns[] = $key . '__' . $fkey;
                        }
                    } elseif ($value->getColumnType()) {
                        $this->addModifyColumn($table, $key, $value->getColumnType(), '', 'NULL');
                        $protectedColumns[] = $key;
                    }

                    $this->addIndexToField($value, $table, 'getColumnType', true, true);
                }
            }
        }

        $this->removeUnusedColumns($table, $columnsToRemove, $protectedColumns);

        $validLanguages = Tool::getValidLanguages();

        if ($container instanceof DataObject\ClassDefinition || $container instanceof DataObject\Objectbrick\Definition) {
            foreach ($validLanguages as &$language) {
                $queryTable = $this->getQueryTableName();
                $queryTable .= '_'.$language;

                $this->db->query(
                    'CREATE TABLE IF NOT EXISTS `'.$queryTable."` (
                      `ooo_id` int(11) NOT NULL default '0',
                      `language` varchar(10) NOT NULL DEFAULT '',
                      PRIMARY KEY (`ooo_id`,`language`),
                      INDEX `ooo_id` (`ooo_id`),
                      INDEX `language` (`language`)
                    ) DEFAULT CHARSET=utf8mb4;"
                );

                $this->handleEncryption($this->model->getClass(), [$queryTable]);

                // create object table if not exists
                $protectedColumns = ['ooo_id', 'language'];

                $existingColumns = $this->getValidTableColumns($queryTable, false); // no caching of table definition
                $columnsToRemove = $existingColumns;

                DataObject\ClassDefinition\Service::updateTableDefinitions($this->tableDefinitions, [$queryTable]);

                if ($container instanceof DataObject\Objectbrick\Definition) {
                    $containerKey = $context['containerKey'];
                    $container = DataObject\Objectbrick\Definition::getByKey($containerKey);
                    $fieldDefinitions = $container->getFielddefinition(
                        'localizedfields',
                        ['suppressEnrichment' => true]
                    )->getFielddefinitions(['suppressEnrichment' => true]);
                } else {
                    $fieldDefinitions = $this->model->getClass()->getFielddefinition(
                        'localizedfields',
                        ['suppressEnrichment' => true]
                    )->getFielddefinitions(['suppressEnrichment' => true]);
                }

                // add non existing columns in the table
                if (is_array($fieldDefinitions) && count($fieldDefinitions)) {
                    foreach ($fieldDefinitions as $value) {
                        if ($value instanceof DataObject\ClassDefinition\Data\QueryResourcePersistenceAwareInterface) {
                            $key = $value->getName();

                            // if a datafield requires more than one column in the query table
                            if (is_array($value->getQueryColumnType())) {
                                foreach ($value->getQueryColumnType() as $fkey => $fvalue) {
                                    $this->addModifyColumn($queryTable, $key.'__'.$fkey, $fvalue, '', 'NULL');
                                    $protectedColumns[] = $key.'__'.$fkey;
                                }
                            } elseif ($value->getQueryColumnType()) {
                                $this->addModifyColumn($queryTable, $key, $value->getQueryColumnType(), '', 'NULL');
                                $protectedColumns[] = $key;
                            }

                            // add indices
                            $this->addIndexToField($value, $queryTable, 'getQueryColumnType');
                        }
                    }
                }

                // remove unused columns in the table
                $this->removeUnusedColumns($queryTable, $columnsToRemove, $protectedColumns);
            }

            $this->createLocalizedViews();
        }

        $this->tableDefinitions = null;
    }

    /**
     * @param string $fieldname
     * @param string $language
     *
     * @return array
     */
    private function getFieldDefinitionParams(string $fieldname, string $language)
    {
        return [
            'owner' => $this->model,
            'fieldname' => $fieldname,
            'language' => $language,
        ];
    }
}
