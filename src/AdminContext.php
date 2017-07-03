<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\node\Entity\NodeType;
use Behat\Gherkin\Node\TableNode;

/**
 * Defines application features from the specific context.
 */
class AdminContext extends AnonymousContext implements Context, SnippetAcceptingContext {

  /** @var array */
  private $entities = [];

  /**
   * @Given /^I am on the add "([^"]*)" page$/
   *
   * @param string $contentTypeName
   *   The content type's machine name or label.
   * @throws \Exception
   */
  public function iAmOnTheAddPage($contentTypeName) {
    $nodeTypes = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      /** @var \Drupal\node\Entity\NodeType $nodeType */
      if ($this->matchesMachineName($nodeType, $contentTypeName) || $this->matchesLabel($nodeType, $contentTypeName)) {
        $this->assertAtPath("/node/add/{$nodeType->id()}");
        return;
      }
    }

    throw new Exception("Unknown content type: {$contentTypeName}");
  }

  /**
   * @param \Drupal\node\Entity\NodeType $nodeType
   *   The node type to check against.
   * @param string $machineName
   *   The machine name to match.
   * @return bool
   *   Whether or not the NodeType matches the machine name.
   */
  private function matchesMachineName(NodeType $nodeType, $machineName) {
    return $nodeType->id() == $machineName;
  }

  /**
   * @param \Drupal\node\Entity\NodeType $nodeType
   *   The node type to check against.
   * @param string $label
   *   The label to match.
   * @return bool
   *   Whether or not the NodeType matches the label.
   */
  private function matchesLabel(NodeType $nodeType, $label) {
    return $nodeType->label() == $label;
  }

  /**
   * @Given /^I am on the edit "([^"]*)" block page$/
   * @param string $blockName
   *   The name of the block we want to edit.
   * @throws \Exception
   */
  public function iAmOnTheEditBlockPage($blockName) {
    $blockQuery = \Drupal::entityTypeManager()->getStorage('block_content')->getQuery();
    $blockQuery->condition('info', $blockName);
    $ids = $blockQuery->execute();

    if (count($ids) == 0) {
      throw new Exception("No block with {$blockName} could be found.");
    }
    elseif (count($ids) > 1) {
      throw new Exception("{$blockName} could not be resolved to a single block.");
    }

    $id = reset($ids);
    $this->assertAtPath("block/$id");
  }

  /**
   * @Then /^I should not see the "([^"]*)" tab$/
   * @param string $label
   *   The label to look for.
   * @throws \Exception
   */
  public function iShouldNotSeeTheTab($label) {
    $currentPage = $this->getMink()->getSession()->getPage();
    $hasTabs = $currentPage->has('css', '.tabs');
    if ($hasTabs) {
      $tabs = $currentPage->find('css', '.tabs');
      if (strpos($label, $tabs->getText()) !== FALSE) {
        throw new Exception("The ${label} tab has been found in the tabs.");
      }
    };
  }

  /**
   * Remove any created entities.
   *
   * @AfterScenario
   */
  public function cleanEntities() {
    foreach ($this->entities as $entity_type => $entities) {
      \Drupal::entityTypeManager()->getStorage($entity_type)->delete($entities);
    }
  }

  /**
   * @Given :entity_type entities:
   */
  public function mediaEntities($entity_type, TableNode $entityTable) {
    if (empty($this->entities[$entity_type])) {
      $this->entities[$entity_type] = [];
    }

    $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
    foreach ($entityTable->getHash() as $entityHash) {
      $entity = $storage->create($entityHash);
      $entity->save();
      $this->entities[$entity_type][] = $entity;
    }
  }

  /**
   * Attempts to click a link in a details block containing giving text. This is
   * for add/edit content forms containing media fields for instance.
   *
   * @Given I click :clickable in the fieldset containing :text
   */
  public function clickLinkInFieldset($clickable, $fieldsetText) {
    $clickableElement = $this->assertClickableInFieldset($clickable, $fieldsetText);
    $clickableElement->click();
  }

  /**
   * @param string $text
   *
   * @return \Behat\Mink\Element\NodeElement[]
   * @throws \Exception
   */
  private function findFieldsetsWithText($text) {
    /** @var \Behat\Mink\Element\NodeElement[] $fieldsets */
    $fieldsets = $this->getSession()->getPage()->findAll('css', 'fieldset, details');

    $matches = [];
    foreach ($fieldsets as $fieldset) {
      if (strpos($fieldset->getText(), $text) !== FALSE) {
        $matches[] = $fieldset;
      }
    }

    if (empty($matches)) {
      throw new \Exception(sprintf('Could not find a fieldset containing %s', $text));
    }

    return $matches;
  }

  /**
   * Attempts to find a link in a details block containing giving text. This is
   * for add/edit content forms containing media fields for instance.

   * @then I (should )see the :clickable link/button in the fieldset containing :text
   * @return ElementNode;
   */
  public function assertClickableInFieldset($clickable, $fieldsetText) {
    $fieldsets = $this->findFieldsetsWithText($fieldsetText);

    foreach ($fieldsets as $fieldset) {
      if ($clickableElement = $fieldset->findLink($clickable)) {
        return $clickableElement;
      }
      elseif ($clickableElement = $fieldset->findButton($clickable)) {
        return $clickableElement;
      }
    }

    throw new \Exception(sprintf('Found a row fieldset "%s", but no "%s" link on the page %s', $fieldsetText, $clickable, $this->getSession()->getCurrentUrl()));
  }

  /**
   * @When I click summary :summaryText
   */
  public function clickDetailsSummary($summaryText) {
    $summaryElements = $this->getSession()->getPage()->findAll('css', 'summary');

    foreach ($summaryElements as $element) {
      if ($element->getText() === $summaryText) {
        $element->click();
        return;
      }
    }

    throw new \Exception("No \"{$summaryText}\" summary found");
  }

  /**
   * @Then I should see :text in the entity browser
   */
  public function iShouldSeeInTheEntityBrowser($text) {
    $this->getSession()->switchToIFrame('entity_browser_iframe_media');

    try {
      $this->assertTextVisible($text);
    }
    catch (\Exception $e) {
      $this->getSession()->switchToWindow();
      throw $e;
    }
  }

}
