"""
q8 לקובץ ONNX אחד, כמו ב-transformers.js — ולפני כן מקפל Transpose של משקל קבוע לקבוע.

למה: ב-Marian ה-lm_head חולק את המשקל עם ה-embedding, והייצוא כותב אותו כ-Transpose(embedding) שרץ
בכל טוקן. בלי הקיפול ה-MatMul לא מכומת ("non constant B") — כל טוקן איטי פי 5–7. onnxslim היה מקפל
את זה בעצמו, אבל עם קיפול הקבועים הוא שובר את הענף של ה-If במפענח המאוחד ("Starts must be a 1-D array").

    python fold_q8.py <in.onnx> <out.onnx> [--slim]   # ‏--slim: ‏onnxslim בלי הסקת צורות ובלי קיפול קבועים
מחייב PYTHONPATH לתיקייה שבה scripts/ של transformers.js.
"""
import sys
import onnx
import numpy as np
from onnx import numpy_helper
from onnxruntime.quantization import QuantType
from scripts.quantize import quantize_q8, get_operators, QUINT8_OPS

src, dst = sys.argv[1], sys.argv[2]
slim = '--slim' in sys.argv[3:]

m = onnx.load(src)
inits = {i.name: i for i in m.graph.initializer}
folded = 0


def fold(g):
    global folded
    for nd in list(g.node):
        for a in nd.attribute:
            if a.g:
                fold(a.g)
        if nd.op_type == 'Transpose' and nd.input[0] in inits:
            perm = next((list(a.ints) for a in nd.attribute if a.name == 'perm'), None)
            arr = numpy_helper.to_array(inits[nd.input[0]])
            t = np.ascontiguousarray(arr.transpose(perm) if perm else arr.T)
            m.graph.initializer.append(numpy_helper.from_array(t, nd.output[0]))
            inits[nd.output[0]] = m.graph.initializer[-1]
            g.node.remove(nd)
            folded += 1


fold(m.graph)
print(f'{src}: folded {folded} Transpose', flush=True)
if slim:
    import onnxslim
    m = onnxslim.slim(m, no_shape_infer=True, no_constant_folding=True)
    print('onnxslim: no_shape_infer, no_constant_folding', flush=True)

wt = QuantType.QUInt8 if any(x in QUINT8_OPS for x in get_operators(m)) else QuantType.QInt8
quantize_q8(m, dst, per_channel=False, reduce_range=False, weight_type=wt, op_block_list=None)
print(f'-> {dst}', flush=True)
