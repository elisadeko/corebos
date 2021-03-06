<?php
/*+***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/
require_once 'include/Webservices/VtigerCRMActorMeta.php';

class VtigerActorOperation extends WebserviceEntityOperation {
	protected $entityTableName;
	protected $moduleFields;
	protected $isEntity = false;
	protected $element;
	protected $id;

	public function __construct($webserviceObject, $user, $adb, $log) {
		parent::__construct($webserviceObject, $user, $adb, $log);
		$this->entityTableName = $this->getActorTables();
		if ($this->entityTableName === null) {
			throw new WebServiceException(WebServiceErrorCode::$UNKOWNENTITY, 'Entity is not associated with any tables');
		}
		$this->meta = $this->getMetaInstance();
		$this->moduleFields = null;
		$this->element = null;
		$this->id = null;
	}

	protected function getMetaInstance() {
		if (empty(WebserviceEntityOperation::$metaCache[$this->webserviceObject->getEntityName()][$this->user->id])) {
			WebserviceEntityOperation::$metaCache[$this->webserviceObject->getEntityName()][$this->user->id]
				= new VtigerCRMActorMeta($this->entityTableName, $this->webserviceObject, $this->pearDB, $this->user);
		}
		return WebserviceEntityOperation::$metaCache[$this->webserviceObject->getEntityName()][$this->user->id];
	}

	protected function getActorTables() {
		static $actorTables = array();

		if (isset($actorTables[$this->webserviceObject->getEntityName()])) {
			return $actorTables[$this->webserviceObject->getEntityName()];
		}
		$sql = 'select table_name from vtiger_ws_entity_tables where webservice_entity_id=?';
		$result = $this->pearDB->pquery($sql, array($this->webserviceObject->getEntityId()));
		$tableName = null;
		if ($result) {
			$rowCount = $this->pearDB->num_rows($result);
			for ($i=0; $i<$rowCount; ++$i) {
				$row = $this->pearDB->query_result_rowdata($result, $i);
				$tableName = $row['table_name'];
			}
			// Cache the result for further re-use
			$actorTables[$this->webserviceObject->getEntityName()] = $tableName;
		}
		return $tableName;
	}

	public function getMeta() {
		return $this->meta;
	}

	protected function getNextId($elementType, $element) {
		if (strcasecmp($elementType, 'Groups') === 0) {
			$tableName='vtiger_users';
		} else {
			$tableName = $this->entityTableName;
		}
		$meta = $this->getMeta();
		if (strcasecmp($elementType, 'Groups') !== 0 && strcasecmp($elementType, 'Users') !== 0) {
			$sql = 'update '.$tableName.'_seq set id=(select max('.$meta->getIdColumn().") from $tableName)";
			$this->pearDB->pquery($sql, array());
		}
		return $this->pearDB->getUniqueId($tableName);
	}

	public function __create($elementType, $element) {
		require_once 'include/utils/utils.php';

		$this->id=$this->getNextId($elementType, $element);

		$element[$this->meta->getObectIndexColumn()] = $this->id;

		//Insert into group vtiger_table
		$query = "insert into {$this->entityTableName}(".implode(',', array_keys($element)).') values('.generateQuestionMarks(array_keys($element)).')';
		$result = null;
		$transactionSuccessful = vtws_runQueryAsTransaction($query, array_values($element), $result);
		return $transactionSuccessful;
	}

	public function create($elementType, $element) {
		$element = DataTransform::sanitizeForInsert($element, $this->meta);

		$element = $this->restrictFields($element);

		$success = $this->__create($elementType, $element);
		if (!$success) {
			throw new WebServiceException(
				WebServiceErrorCode::$DATABASEQUERYERROR,
				vtws_getWebserviceTranslatedString('LBL_'.WebServiceErrorCode::$DATABASEQUERYERROR)
			);
		}
		return $this->retrieve(vtws_getId($this->meta->getEntityId(), $this->id));
	}

	protected function restrictFields($element, $selectedOnly = false) {
		$fields = $this->getModuleFields();
		$newElement = array();
		foreach ($fields as $field) {
			if (isset($element[$field['name']])) {
				$newElement[$field['name']] = $element[$field['name']];
			} elseif ($field['name'] != 'id' && $selectedOnly == false) {
				$newElement[$field['name']] = '';
			}
		}
		return $newElement;
	}

	public function __retrieve($id) {
		$query = "select * from {$this->entityTableName} where {$this->meta->getObectIndexColumn()}=?";
		$transactionSuccessful = vtws_runQueryAsTransaction($query, array($id), $result);
		if (!$transactionSuccessful) {
			throw new WebServiceException(WebServiceErrorCode::$DATABASEQUERYERROR, vtws_getWebserviceTranslatedString('LBL_'.WebServiceErrorCode::$DATABASEQUERYERROR));
		}
		$db = $this->pearDB;
		if ($result) {
			$rowCount = $db->num_rows($result);
			if ($rowCount >0) {
				$this->element = $db->query_result_rowdata($result, 0);
				return true;
			}
		}
		return false;
	}

	public function retrieve($id) {
		$ids = vtws_getIdComponents($id);
		$elemId = $ids[1];
		$success = $this->__retrieve($elemId);
		if (!$success) {
			throw new WebServiceException(WebServiceErrorCode::$RECORDNOTFOUND, 'Record not found');
		}
		$element = $this->getElement();
		if (isset($element['folderid']) && !isset($element['id'])) {
			$element['id'] = $element['folderid'];
			unset($element['folderid']);
		}
		return DataTransform::filterAndSanitize($element, $this->meta);
	}

	public function massRetrieve($wsIds) {
		global $adb;
		$rdo = array();
		$query = "select * from {$this->entityTableName} where {$this->meta->getObectIndexColumn()} in (" . generateQuestionMarks($wsIds) . ')';
		$rs = $adb->pquery($query, $wsIds);
		while (!$rs->EOF) {
			$element = $rs->FetchRow();
			if (isset($element['folderid']) && !isset($element['id'])) {
				$element['id'] = $element['folderid'];
				unset($element['folderid']);
			}
			$rdo[] = DataTransform::filterAndSanitize($element, $this->meta);
		}
		return $rdo;
	}

	public function __update($element, $id) {
		$columnStr = 'set '.implode('=?,', array_keys($element)).' =? ';
		$query = 'update '.$this->entityTableName.' '.$columnStr.'where '. $this->meta->getObectIndexColumn().'=?';
		$params = array_values($element);
		$params[] = $id;
		$result = null;
		return vtws_runQueryAsTransaction($query, $params, $result);
	}

	public function update($element) {
		$ids = vtws_getIdComponents($element['id']);
		$element = DataTransform::sanitizeForInsert($element, $this->meta);
		$element = $this->restrictFields($element);

		$success = $this->__update($element, $ids[1]);
		if (!$success) {
			throw new WebServiceException(WebServiceErrorCode::$DATABASEQUERYERROR, vtws_getWebserviceTranslatedString('LBL_'.WebServiceErrorCode::$DATABASEQUERYERROR));
		}
		return $this->retrieve(vtws_getId($this->meta->getEntityId(), $ids[1]));
	}

	public function __revise($element, $id) {
		$columnStr = 'set '.implode('=?,', array_keys($element)).' =? ';
		$query = 'update '.$this->entityTableName.' '.$columnStr.'where '.$this->meta->getObectIndexColumn().'=?';
		$params = array_values($element);
		$params[] = $id;
		$result = null;
		return vtws_runQueryAsTransaction($query, $params, $result);
	}

	public function revise($element) {
		$ids = vtws_getIdComponents($element['id']);

		$element = DataTransform::sanitizeForInsert($element, $this->meta);
		$element = $this->restrictFields($element, true);

		$success = $this->__retrieve($ids[1]);
		if (!$success) {
			throw new WebServiceException(WebServiceErrorCode::$RECORDNOTFOUND, 'Record not found');
		}

		$allDetails = $this->getElement();
		foreach ($allDetails as $index => $value) {
			if (!isset($element)) {
				$element[$index] = $value;
			}
		}
		$success = $this->__revise($element, $ids[1]);
		if (!$success) {
			throw new WebServiceException(
				WebServiceErrorCode::$DATABASEQUERYERROR,
				vtws_getWebserviceTranslatedString('LBL_'.WebServiceErrorCode::$DATABASEQUERYERROR)
			);
		}

		return $this->retrieve(vtws_getId($this->meta->getEntityId(), $ids[1]));
	}

	public function __delete($elemId) {
		$result = null;
		$query = 'delete from '.$this->entityTableName.' where '. $this->meta->getObectIndexColumn().'=?';
		$transactionSuccessful = vtws_runQueryAsTransaction($query, array($elemId), $result);
		return $transactionSuccessful;
	}

	public function delete($id) {
		$ids = vtws_getIdComponents($id);
		$elemId = $ids[1];

		$success = $this->__delete($elemId);
		if (!$success) {
			throw new WebServiceException(
				WebServiceErrorCode::$DATABASEQUERYERROR,
				vtws_getWebserviceTranslatedString('LBL_'.WebServiceErrorCode::$DATABASEQUERYERROR)
			);
		}
		return array('status'=>'successful');
	}

	public function describe($elementType) {
		$app_strings = VTWS_PreserveGlobal::getGlobal('app_strings');
		vtws_preserveGlobal('current_user', $this->user);
		$label = (isset($app_strings[$elementType])) ? $app_strings[$elementType] : $elementType;
		$createable = $this->meta->hasWriteAccess();
		$updateable = $this->meta->hasWriteAccess();
		$deleteable = $this->meta->hasDeleteAccess();
		$retrieveable = $this->meta->hasReadAccess();
		$fields = $this->getModuleFields();
		return array(
			'label'=>getTranslatedString($label, $this->meta->getPermissionModule()),
			'label_raw'=>$label,
			'name'=>$elementType,
			'createable'=>$createable,
			'updateable'=>$updateable,
			'deleteable'=>$deleteable,
			'retrieveable'=>$retrieveable,
			'fields'=>$fields,
			'idPrefix'=>$this->meta->getEntityId(),
			'isEntity'=>$this->isEntity,
			'labelFields'=>$this->meta->getNameFields()
		);
	}

	public function getModuleFields() {
		if ($this->moduleFields === null) {
			$fields = array();
			$moduleFields = $this->meta->getModuleFields();
			foreach ($moduleFields as $webserviceField) {
				$fields[] = $this->getDescribeFieldArray($webserviceField);
			}
			$this->moduleFields = $fields;
		}
		return $this->moduleFields;
	}

	public function getDescribeFieldArray($webserviceField) {
		$app_strings = VTWS_PreserveGlobal::getGlobal('app_strings');
		$fieldLabel = $webserviceField->getFieldLabelKey();
		if (isset($app_strings[$fieldLabel])) {
			$fieldLabel = $app_strings[$fieldLabel];
		}
		if (strcasecmp($webserviceField->getFieldName(), $this->meta->getObectIndexColumn()) === 0) {
			return $this->getIdField($fieldLabel);
		}

		$typeDetails = $this->getFieldTypeDetails($webserviceField);

		//set type name, in the type details array.
		$typeDetails['name'] = $webserviceField->getFieldDataType();
		$editable = $this->isEditable($webserviceField);

		$describeArray = array(
			'name' => $webserviceField->getFieldName(),
			'label' => getTranslatedString($fieldLabel, $this->meta->getPermissionModule()),
			'label_raw' => $fieldLabel,
			'mandatory' => $webserviceField->isMandatory(),
			'type' => $typeDetails,
			'nullable' => $webserviceField->isNullable(),
			'editable' => $editable
		);
		if ($webserviceField->hasDefault()) {
			$describeArray['default'] = $webserviceField->getDefault();
		}
		return $describeArray;
	}

	public function getIdField($label) {
		return array(
			'name'=>'id',
			'label'=> getTranslatedString($label, $this->meta->getPermissionModule()),
			'label_raw' => $label,
			'mandatory'=>false,
			'type'=>'id',
			'editable'=>false,
			'type'=>array('name'=>'autogenerated'),
			'nullable'=>false,
			'default'=>''
		);
	}

	public function wsVTQL2SQL($q, &$meta, &$queryRelatedModules) {
		$queryRelatedModules = array();
		$meta = null;
		$parser = new Parser($this->user, $q);
		$error = $parser->parse();
		if ($error) {
			return $parser->getError();
		}
		$mysql_query = $parser->getSql();
		$meta = $parser->getObjectMetaData();
		return $mysql_query;
	}

	public function query($q) {
		$meta = null;
		$queryRelatedModules = array();
		$mysql_query = $this->wsVTQL2SQL($q, $meta, $queryRelatedModules);
		$this->pearDB->startTransaction();
		$result = $this->pearDB->pquery($mysql_query, array());
		$error = $this->pearDB->hasFailedTransaction();
		$this->pearDB->completeTransaction();

		if ($error) {
			throw new WebServiceException(
				WebServiceErrorCode::$DATABASEQUERYERROR,
				vtws_getWebserviceTranslatedString('LBL_'.WebServiceErrorCode::$DATABASEQUERYERROR)
			);
		}

		$noofrows = $this->pearDB->num_rows($result);
		$output = array();
		for ($i=0; $i<$noofrows; $i++) {
			$row = $this->pearDB->fetchByAssoc($result, $i);
			if (!$meta->hasPermission(EntityMeta::$RETRIEVE, (isset($row['crmid']) ? $row['crmid'] : ''))) {
				continue;
			}
			$output[] = DataTransform::sanitizeDataWithColumn($row, $meta);
		}

		return $output;
	}

	protected function getElement() {
		return $this->element;
	}
}
?>