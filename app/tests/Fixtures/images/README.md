# Image fixtures

`tiny.png` remains the small fixture for isolated tests.

`demo-0.png` through `demo-3.png` are original, procedurally drawn PHP/GD product illustrations for the local fake engine. Each is 1024 × 768 with a different palette, and visibly labeled as local simulation. They contain no external assets and do not respond to generation or editing instructions. They deliberately remain below the 3840-pixel delivery target. The output index selects the corresponding image.

These files are fixture sources only. Accepted demo outputs still pass through the normal downloader into private object storage and use authorized media delivery routes. Existing stored outputs are not replaced when fixtures change.
