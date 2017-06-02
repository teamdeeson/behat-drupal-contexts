<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\node\Entity\NodeType;

/**
 * Defines application features from the specific context.
 */
class AdminContext extends AnonymousContext implements Context, SnippetAcceptingContext {

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

}
