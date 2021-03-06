<?php

error_reporting(E_ALL);

require_once("simpletype.php");
require_once("operation.php");

$argsfile = $argv[1];
$argsjsonstring = file_get_contents($argsfile);
$argsjson = json_decode($argsjsonstring, true);

function isA($what) {
	global $argsjson;
	if ($argsjson === NULL)
		return false;
	return array_key_exists($what, $argsjson);
}

function A($what) {
	global $argsjson;
	return $argsjson[$what];
}

// VT: vector type name
$VT = SimpleType::from_string(A("VT"));
$ST = $VT->get_scalar();

ob_start();

function footer() {
	global $ST, $VT;
	$output_so_far = ob_get_contents();
	ob_end_clean();
	echo "/* AUTOGENERATED PART */", PHP_EOL;
	echo "#define NULL 0", PHP_EOL;
	echo A("RayDescriptor"), PHP_EOL;
	echo A("DirectionDescriptor"), PHP_EOL;

	ob_start(); // so we can use the OperationManager also in the preamble code
	if (A("two_pass")) {
	?>
__global IntermediateStorage* get_intermediate_storage(__global IntermediateStorage* sweep_data, __global RayDescriptor* rays, __global DirectionDescriptor* directions, __global uint* ray_lookup_table, <?=$VT->paramdecl()?> posvec, uint direction_idx, uint2 ray_interval) {
	<?=$ST?> projected[<?=A("dimension")-1?>];
	<?=$ST?> len_sqr;
	<?php
	for ($i=0; $i<A("dimension")-1; $i++) {?>
	len_sqr = dot(directions[direction_idx].span_vec_<?=$i?>, directions[direction_idx].span_vec_<?=$i?>);
	projected[<?=$i?>] = dot(posvec, directions[direction_idx].span_vec_<?=$i?>) / (len_sqr);
	if (fabs(projected[<?=$i?>]) >= <?=A("max_rays_per_dimension")/2.0?>) {
		// out of bounds of the ray lookup table
		return NULL;
	}
	<?php } ?>
	uint rltidx = direction_idx * <?=pow(A("max_rays_per_dimension"),A("dimension")-1)?> <?php // compare with function calc_ray_lookup_table_idx in RayFactory.hpp, must yield same results
	$mul_accu = 1;
	for ($i=0;$i<A("dimension")-1;$i++) {
		echo " + ((int)round(projected[$i]+".(A("max_rays_per_dimension")/2.0)."))*$mul_accu";
		$mul_accu *= A("max_rays_per_dimension");
	}?>;
	uint rayidx = ray_lookup_table[rltidx];
	if (rayidx == 0) {
		// there was no ray there
		return NULL;
	}
	rayidx--; // in the lookup table 0 stands for "no ray" and n stands for "ray n-1"
	uint mem_begin = rays[rayidx].memory_begin;
	len_sqr = dot(directions[direction_idx].step_vec, directions[direction_idx].step_vec);
	<?=$ST?> mem_offs = dot(posvec, directions[direction_idx].step_vec);
	<?=$ST?> begin_offs = dot(rays[rayidx].origin, directions[direction_idx].step_vec); // PERF maybe this result can be cached somewhere?
	int ray_mem_position = (int)(round((mem_offs-begin_offs)/len_sqr));
	if (ray_mem_position >= rays[rayidx].num_steps || ray_mem_position < 0) {
		return NULL;
	}
	return &(sweep_data[mem_begin + ray_mem_position - rays[ray_interval.s0].memory_begin]);
}
	<?php
	} // two_pass
	?>

typedef struct {
	<?=$VT->decl0()?> current_position<?=$VT->decl1()?>;
	<?=$VT->decl0()?> ray_step<?=$VT->decl1()?>;
	uint ray_id;
	int steps_left;
	<?=A("two_pass")?"uint memory_idx;".PHP_EOL:""?>
} FrameworkState;

void init_framework(FrameworkState* state, __global RayDescriptor* rays, __global DirectionDescriptor* directions, uint2 ray_interval) {
	uint rid = get_global_id(0)+ray_interval.s0;
	state->ray_id = rid;
	<?=assign('#G'.$VT->to_string(),"rays[rid].origin",$VT->to_string(),($VT->pass_by_reference()?"":"&")."(state->current_position)")?>;
	uint did = rays[rid].direction_idx;
	<?=assign('#G'.$VT->to_string(),"directions[did].step_vec",$VT->to_string(),($VT->pass_by_reference()?"":"&")."(state->ray_step)")?>;
	state->steps_left = rays[rid].num_steps;
	<?php if(A("two_pass")) {?>
	state->memory_idx = rays[rid].memory_begin - rays[ray_interval.s0].memory_begin;
	<?php } ?>
}

bool sweep_step(FrameworkState* state) {
	<?=add($VT->to_string(),"state->ray_step",$VT->to_string(),"state->current_position",$VT->to_string(),"state->current_position")?>;
	state->steps_left = state->steps_left - 1;
	<?php if (A("two_pass")) {?>
	state->memory_idx = state->memory_idx + 1;
	<?php } ?>
	return state->steps_left >= 0;
}
	<?php
	$framework_code = ob_get_contents();
	ob_end_clean();
	echo OperationManager::declarations();
	echo $framework_code;
	echo PHP_EOL, "/* NOT AUTOGENERATED PART */", PHP_EOL;
	echo $output_so_far;
}
?>
