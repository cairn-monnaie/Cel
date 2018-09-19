<?php

/* CairnUserBundle:Banking:deposit.html.twig */
class __TwigTemplate_19e45ae87604b606c9c90cfc3faa4ba51f7fe8179650615d176211eb22e1113f extends Twig_Template
{
    private $source;

    public function __construct(Twig_Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        // line 3
        $this->parent = $this->loadTemplate("CairnUserBundle::layout.html.twig", "CairnUserBundle:Banking:deposit.html.twig", 3);
        $this->blocks = array(
            'body' => array($this, 'block_body'),
            'javascripts' => array($this, 'block_javascripts'),
        );
    }

    protected function doGetParent(array $context)
    {
        return "CairnUserBundle::layout.html.twig";
    }

    protected function doDisplay(array $context, array $blocks = array())
    {
        $this->parent->display($context, array_merge($this->blocks, $blocks));
    }

    // line 5
    public function block_body($context, array $blocks = array())
    {
        // line 6
        echo "    ";
        $this->displayParentBlock("body", $context, $blocks);
        echo "

    <div class=\"well\">
      ";
        // line 9
        echo         $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->renderBlock(($context["formUser"] ?? null), 'form_start');
        echo "
      ";
        // line 10
        echo $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->searchAndRenderBlock(($context["formUser"] ?? null), 'rest');
        echo "
      ";
        // line 11
        echo         $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->renderBlock(($context["formUser"] ?? null), 'form_end');
        echo "

    </div>

    <div class=\"well\">
      ";
        // line 16
        echo         $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->renderBlock(($context["formDeposit"] ?? null), 'form_start');
        echo "
      ";
        // line 17
        echo $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->searchAndRenderBlock(twig_get_attribute($this->env, $this->source, ($context["formDeposit"] ?? null), "toAccount", array()), 'widget', array("attr" => array("class" => "hidden-row")));
        echo "
      ";
        // line 18
        echo $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->searchAndRenderBlock(($context["formDeposit"] ?? null), 'rest');
        echo "
      ";
        // line 19
        echo         $this->env->getRuntime('Symfony\Bridge\Twig\Form\TwigRenderer')->renderBlock(($context["formDeposit"] ?? null), 'form_end');
        echo "

    </div>

    ";
        // line 23
        echo twig_include($this->env, $context, "CairnUserBundle:Banking:accounts_list.html.twig", array("accounts" => ($context["accounts"] ?? null), "type" => "credit"));
        echo "

";
    }

    // line 27
    public function block_javascripts($context, array $blocks = array())
    {
        // line 28
        echo "    <script type=\"text/javascript\" src=\"http://ajax.googleapis.com/ajax/libs/jquery/1.5.2/jquery.min.js\"></script>
    <script type=\"text/javascript\" src=\"http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.12/jquery-ui.min.js\"></script>

    <script>
        jQuery(function (\$) {
            \$formAccountOwner = \$('#deposit_toAccount_owner');    
            \$formAccountId = \$('#deposit_toAccount_id');    
           
            \$containerAccount = \$('.account'); 
            \$containerAccount.click(function (e){
                \$formAccountOwner.val(\$(this).children(\"em\")[0].innerText) ;
                \$formAccountId.val(\$(this).children(\"span:last\")[0].innerText);
            });
        });
    </script>
";
    }

    public function getTemplateName()
    {
        return "CairnUserBundle:Banking:deposit.html.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  88 => 28,  85 => 27,  78 => 23,  71 => 19,  67 => 18,  63 => 17,  59 => 16,  51 => 11,  47 => 10,  43 => 9,  36 => 6,  33 => 5,  15 => 3,);
    }

    public function getSourceContext()
    {
        return new Twig_Source("", "CairnUserBundle:Banking:deposit.html.twig", "/var/www/Symfony/CairnB2B/src/Cairn/UserBundle/Resources/views/Banking/deposit.html.twig");
    }
}
