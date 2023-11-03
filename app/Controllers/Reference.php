<?php namespace GaletteTelemetry\Controllers;

use GaletteTelemetry\Models\Reference as ReferenceModel;
use GaletteTelemetry\Models\DynamicReference;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class Reference extends ControllerAbstract
{

    public function view(Request $request, Response $response): Response
    {
        $get = $request->getQueryParams();
        // default session param for this controller
        if (!isset($_SESSION['reference'])) {
            $_SESSION['reference'] = [
                "orderby" => 'created_at',
                "sort"    => "desc"
            ];
        }

        $_SESSION['reference']['pagination'] = 15;
        $order_field = $_SESSION['reference']['orderby'];
        $order_sort  = $_SESSION['reference']['sort'];

        //prepare model and common queries
        $ref = new ReferenceModel();
        $model = $ref->newInstance();
        $where = [
            ['is_displayed', '=', true]
        ];

        $model = call_user_func_array(
            [
                $model,
                'select'
            ],
            ['reference.*']
        );

        $current_filters = [];
        if (isset($_SESSION['reference']['filters'])) {
            if (!empty($_SESSION['reference']['filters']['name'])) {
                $current_filters['name'] = $_SESSION['reference']['filters']['name'];
                $where[] = ['name', 'like', "%{$_SESSION['reference']['filters']['name']}%"];
            }
            if (!empty($_SESSION['reference']['filters']['country'])) {
                $current_filters['country'] = $_SESSION['reference']['filters']['country'];
                $where[] = ['country', '=', strtolower($_SESSION['reference']['filters']['country'])];
            }
        }

        $model->where($where);
        if (count($where) > 1) {
            //calculate filtered number of references
            $current_filters['count'] = $model->count('reference.id');
        }

        $model->orderBy(
            'reference.' . $order_field,
            $order_sort
        );

        $references = $model->paginate($_SESSION['reference']['pagination']);

        $references->setPath($this->routeparser->urlFor('reference'));

        $ref_countries = [];
        $existing_countries = ReferenceModel::query()->select('country')->groupBy('country')->get();
        foreach ($existing_countries as $existing_country) {
            $ref_countries[] = $existing_country['country'];
        }

        // render in twig view
        $this->view->render(
            $response,
            'default/reference.html.twig',
            [
                'total'         => ReferenceModel::query()->where('is_displayed', '=', true)->count(),
                'class'         => 'reference',
                'showmodal'     => isset($get['showmodal']),
                'uuid'          => $get['uuid'] ?? '',
                'references'    => $references,
                'orderby'       => $_SESSION['reference']['orderby'],
                'sort'          => $_SESSION['reference']['sort'],
                //'dyn_refs'      => $dyn_refs,
                'filters'       => $current_filters,
                'ref_countries' => $ref_countries
            ]
        );
        return $response;
    }

    public function register(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();

        // clean data
        unset($post['g-recaptcha-response']);
        unset($post['csrf_name']);
        unset($post['csrf_value']);

        $ref_data = $post;
        $dyn_data = [];

        $dyn_ref = $this->container->get('project')->getDynamicReferences();
        if (false !== $dyn_ref) {
            foreach (array_keys($dyn_ref) as $ref) {
                if (isset($post[$ref])) {
                    $dyn_data[$ref] = (int)$post[$ref];
                    unset($ref_data[$ref]);
                }
            }
        }

        // alter data
        $ref_data['country'] = strtolower($ref_data['country']);

        // create reference in db
        if ('' == $ref_data['uuid']) {
            $reference = ReferenceModel::query()->create(
                $ref_data
            );
        } else {
            $reference = ReferenceModel::query()->updateOrCreate(
                ['uuid' => $ref_data['uuid']],
                $ref_data
            );
        }

        if (false !== $dyn_ref) {
            $dref = new DynamicReference();
            $dynamics = $dref->newInstance();
            $dynamics->setTable($this->container->get('project')->getSlug() . '_reference');

            /** @phpstan-ignore-next-line */
            $exists = $dynamics->where('reference_id', $reference['id'])->get();

            if (0 === $exists->count()) {
                $dyn_data['reference_id'] = $reference['id'];
                /** @phpstan-ignore-next-line */
                $dynamics->insert(
                    $dyn_data
                );
            } else {
                /** @phpstan-ignore-next-line */
                $dynamics
                    ->where('reference_id', '=', $reference['id'])
                    ->update($dyn_data);
            }
        }

        // send a mail to admin
        $mail = new PHPMailer;
        $mail->setFrom($this->container->get('mail_from'));
        $mail->addAddress($this->container->get('mail_admin'));
        $mail->Subject = "A new reference has been submitted: ".$post['name'];
        $mail->Body    = var_export($post, true);
        $mail->send();

        // store a message for user (displayed after redirect)
        $this->container->get('flash')->addMessage(
            'success',
            'Your reference has been stored! An administrator will moderate it before display on the site.'
        );

        // redirect to ok page
        return $response
            ->withStatus(301)
            ->withHeader(
                'Location',
                $this->routeparser->urlFor('reference')
            );
    }

    public function filter(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        if (isset($post['reset_filters'])) {
            unset($_SESSION['reference']['filters']);
        } else {
            $_SESSION['reference']['filters'] = [
                'name'     => $post['filter_name'],
                'country'  => $post['filter_country']
            ];
        }

        return $response
            ->withStatus(301)
            ->withHeader(
                'Location',
                $this->routeparser->urlFor('reference')
            );
    }

    public function order(Request $request, Response $response, string $field): Response
    {
        if ($_SESSION['reference']['orderby'] == $field) {
            // toggle sort if orderby requested on the same column
            $_SESSION['reference']['sort'] = ($_SESSION['reference']['sort'] == "desc"
                ? "asc"
                : "desc");
        }
        $_SESSION['reference']['orderby'] = $field;

        return $response
            ->withStatus(301)
            ->withHeader(
                'Location',
                $this->routeparser->urlFor('reference')
            );
    }
}
