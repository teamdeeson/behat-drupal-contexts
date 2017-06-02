<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\TableNode;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Webmozart\Assert\Assert;

/**
 * Defines application features from the specific context.
 */
class IntegrationContext implements Context, SnippetAcceptingContext {

  /**
   * @Given the :name :type is :status
   * @param string $name
   *   The extension name.
   * @param string $type
   *   The type of extension.
   * @param string $status
   *   The status to assert.
   * @throws \Exception
   */
  public function assertExtensionStatus($name, $type, $status) {
    // Normalize type and status to prevent failures due to uppercase characters.
    $type = strtolower($type);
    $status = strtolower($status);

    $statuses = [FALSE => 'disabled', TRUE => 'enabled'];

    if (!in_array($status, $statuses)) {
      throw new Exception("Incorrect status given. should be either 'enabled' or 'disabled'");
    }

    $extensions = \Drupal::configFactory()->get('core.extension');
    $enabled = array_key_exists($name, $extensions->get($type));

    if ($statuses[$enabled] != $status) {
      throw new Exception("The {$name} {$type} is {$statuses[$enabled]} while it should be {$status}");
    }
  }

  /**
   * @Given the :bundle :entityType has :fieldName
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field name.
   */
  public function assertEntityTypeBundleHasField($entityType, $bundle, $fieldName) {
    $fieldDefinitions = $this->getFieldDefinitions($entityType, $bundle);
    Assert::keyExists($fieldDefinitions, $fieldName);
  }

  /**
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   An array of FieldDefinitionInterface.
   */
  private function getFieldDefinitions($entityType, $bundle) {
    /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager */
    $fieldManager = Drupal::service('entity_field.manager');
    $fieldDefinitions = $fieldManager->getFieldDefinitions($entityType, $bundle);
    return $fieldDefinitions;
  }

  /**
   * @Given the :bundle :entityType has no :fieldName
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field name.
   */
  public function assertEntityTypeBundleHasNoField($entityType, $bundle, $fieldName) {
    $fieldDefinitions = $this->getFieldDefinitions($entityType, $bundle);
    Assert::keyNotExists($fieldDefinitions, $fieldName);
  }

  /**
   * @Then the :bundle :entity field :fieldName is required
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field name.
   */
  public function assertEntityTypeBundleFieldIsRequired($entityType, $bundle, $fieldName) {
    $definition = $this->getFieldDefinition($entityType, $bundle, $fieldName);
    Assert::true($definition->isRequired());
  }

  /**
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field name.
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   A FieldDefinitionInterface
   */
  private function getFieldDefinition($entityType, $bundle, $fieldName) {
    $definitions = $this->getFieldDefinitions($entityType, $bundle);
    $definition = $definitions[$fieldName];
    return $definition;
  }

  /**
   * @Then the :bundle :entity field :fieldName is optional
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field name.
   */
  public function assertEntityTypeBundleFieldIsNotRequired($entityType, $bundle, $fieldName) {
    $definition = $this->getFieldDefinition($entityType, $bundle, $fieldName);
    Assert::false($definition->isRequired());
  }

  /**
   * @Given the :bundle :entity field :fieldName has setting :settingName containing:
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field's machine name.
   * @param string $settingName
   *   The setting's name.
   * @param \Behat\Gherkin\Node\TableNode $table
   *   The TableNode containing the settings to match.
   */
  public function assertEntityTypeBundleHasFieldSettingContaining($entityType, $bundle, $fieldName, $settingName, TableNode $table) {
    $setting = $this->getFieldSetting($entityType, $bundle, $fieldName, $settingName);

    Assert::allOneOf($table->getColumn(0), $setting);
  }

  /**
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field's machine name.
   * @param string $settingName
   *   The setting's name.
   * @return mixed
   *   The requested field setting.
   */
  private function getFieldSetting($entityType, $bundle, $fieldName, $settingName) {
    $definition = $this->getFieldDefinition($entityType, $bundle, $fieldName);
    $settingsTree = explode('][', trim($settingName, '[]'));
    $settings = $definition->getSettings();
    $value = NestedArray::getValue($settings, $settingsTree);
    return $value;
  }

  /**
   * @Given the :bundle :entity field :fieldName has setting :settingName containing only:
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field's machine name.
   * @param string $settingName
   *   The setting's name.
   * @param \Behat\Gherkin\Node\TableNode $table
   *   The settings which should be matched.
   */
  public function assertEntityTypeBundleHasFieldSettingContainingOnly($entityType, $bundle, $fieldName, $settingName, TableNode $table) {
    $setting = $this->getFieldSetting($entityType, $bundle, $fieldName, $settingName);

    Assert::eq($table->getColumn(0), array_values($setting));
  }

  /**
   * @Given the :entity field :fieldName has cardinality :cardinality
   * @param string $entityType
   *   The entity type id.
   * @param string $fieldName
   *   The field's machine name.
   * @param int $cardinality
   *   The expected cardinality.
   */
  public function assertEntityFieldHasCardinality($entityType, $fieldName, $cardinality) {
    $actual = FieldStorageConfig::loadByName($entityType, $fieldName)
      ->getCardinality();
    Assert::eq($cardinality, $actual);
  }

  /**
   * @Given the :fieldName field is visible on the :viewMode :bundle :entityType form
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $fieldName
   *   The field's machine name.
   * @param string $viewMode
   *   The view mode.
   */
  public function assertEntityFieldVisibleOnForm($entityType, $bundle, $fieldName, $viewMode) {
    $this->getFieldFormDisplayConfig($entityType, $bundle, $viewMode, $fieldName);
  }

  /**
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $viewMode
   *   The view mode.
   * @param string $fieldName
   *   The field's machine name.
   * @return array
   *   The form display configuration.
   */
  private function getFieldFormDisplayConfig($entityType, $bundle, $viewMode, $fieldName) {
    $display = EntityFormDisplay::load("{$entityType}.{$bundle}.{$viewMode}");
    $displayConfig = $display->get('content');
    Assert::keyExists($displayConfig, $fieldName);
    return $displayConfig[$fieldName];
  }

  /**
   * @Given the :viewMode form display for :bundle :entityType uses :widget for :fieldName
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $viewMode
   *   The view mode.
   * @param string $fieldName
   *   The field's machine name.
   * @param string $widget
   *   The widget's machine name to test for.
   */
  public function assertEntityFieldUsesWidget($entityType, $bundle, $viewMode, $fieldName, $widget) {
    $fieldConfig = $this->getFieldFormDisplayConfig($entityType, $bundle, $viewMode, $fieldName);
    Assert::eq($fieldConfig['type'], $widget);
  }

  /**
   * @Given the :viewMode form display for :bundle :entityType has the following :fieldName settings:
   * @param string $entityType
   *   The entity type id.
   * @param string $bundle
   *   The bundle name.
   * @param string $viewMode
   *   The view mode.
   * @param string $fieldName
   *   The field's machine name.
   * @param \Behat\Gherkin\Node\TableNode $table
   *   The table node containing the settings to assert.
   */
  public function assertEntityFieldWidgetSettings($entityType, $bundle, $viewMode, $fieldName, TableNode $table) {
    $fieldConfig = $this->getFieldFormDisplayConfig($entityType, $bundle, $viewMode, $fieldName);

    foreach ($table->getRowsHash() as $key => $value) {
      if ($value === "false") {
        $value = FALSE;
      }
      if ($value === 'true') {
        $value = TRUE;
      }

      Assert::keyExists($fieldConfig['settings'], $key);
      Assert::eq($fieldConfig['settings'][$key], $value);
    }
  }

}
