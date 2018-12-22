<?php
/**
 * User: Wajdi Jurry
 * Date: 20/12/18
 * Time: 08:15 م
 */

namespace Phalcon\Validation\Validators;

use Phalcon\Mvc\Model;
use Phalcon\Validation\Message;
use Phalcon\Validation\Validator;
use Phalcon\Validation\ValidatorInterface;

class ExistenceValidator extends Validator implements ValidatorInterface
{
    /**
     * Executes the validation
     * Usage: $validator->add(
     *           'categoryParentId',
     *           new ExistenceValidator([
     *               'model' => __CLASS__,
     *               'column' => 'categoryId',
     *               'conditions' => [
     *                   'where' => 'categoryVendorId = :vendorId:',
     *                   'bind' => ['vendorId' => $this->categoryVendorId]
     *               ],
     *               'message' => 'Parent category does not exist'
     *           ]
     *       )
     *
     * @param \Phalcon\Validation $validator
     * @param string $attribute
     * @return bool
     * @throws \Exception
     */
    public function validate(\Phalcon\Validation $validator, $attribute)
    {
        $class = $this->getOption('model');
        $column = $this->getOption('column');
        $conditions = $this->getOption('conditions', []);
        $value = $validator->getValue($attribute);
        $message = $this->getOption('message');

        if (empty($column)) {
            throw new \Exception('Please fill column parameter');
        }

        if (!empty($conditions) && (empty($conditions['where']) || empty($conditions['bind']))) {
            throw new \Exception('Validator parameters are invalid');
        }

        /** @var Model $model */
        try {
            $model = $class::model();
        } catch (\Exception $exception) {
            $model = new $class;
        }

        if (empty($value) && empty(($value = $model->$attribute))) {
            return false;
        }

        $query = $model::query()
            ->where($column . ' = :value:');

        if (!empty($conditions)) {
            $query->andWhere($conditions['where']);
        }

        $query->bind(array_merge(['value' => $value], $conditions['bind']));

        $exists = count($query->execute()->toArray()) ? true : false;

        if (!$exists) {
            $validator->appendMessage(new Message($message ?: $attribute . ' does not exists in the model specified'));
        }

        if (count($validator->getMessages())) {
            return false;
        }
        return true;
    }
}
