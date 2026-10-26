# Third-party notices

MATA Dashboard is licensed under the Apache License 2.0 (see [`LICENSE`](LICENSE)).
The repository also ships third-party assets under their own licenses, listed
below. Composer and npm dependencies are not committed; each release includes a
CycloneDX SBOM (`sbom.cdx.json`) listing them.

## Bundled assets

| Component | Version | License | Copyright | Files |
| --- | --- | --- | --- | --- |
| [htmx](https://htmx.org) | 1.9.12 | BSD-2-Clause | (c) 2020, Big Sky Software | `public/assets/js/htmx.min.js` |
| [_hyperscript](https://hyperscript.org) | 0.9.14 | BSD-2-Clause | (c) 2020, Big Sky Software | `public/assets/js/hyperscript.min.js` |
| [Chart.js](https://www.chartjs.org) | 4.4.6 | MIT | (c) 2014-2024 Chart.js Contributors | `public/assets/js/chart.umd.min.js` |
| [Popper](https://popper.js.org) | 2.11.8 | MIT | (c) 2019 Federico Zivolo | `public/assets/js/popper.min.js` |
| [Tippy.js](https://atomiks.github.io/tippyjs/) | 6.x | MIT | (c) 2017-present atomiks | `public/assets/js/tippy.min.js` |
| [Tabulator](https://tabulator.info) | 5.5.0 | MIT | (c) 2015-2023 Oli Folkerd | `public/assets/js/tabulator.min.js`, `public/assets/css/tabulator_*.min.css` |
| [Bulma](https://bulma.io) | 1.0.3 | MIT | (c) 2024 Jeremy Thomas | `public/assets/css/bulma.css`, `public/assets/css/bulma.purged.min.css` (compiled) |
| [Feather](https://feathericons.com) | 4.x | MIT | (c) 2013-2023 Cole Bemis | `public/assets/icons/*.svg` (Feather icons) |
| [Simple Icons](https://simpleicons.org) | 16.34.0 | CC0-1.0 | Simple Icons contributors | `public/assets/icons/frameworks/*.svg`, except Neos, Sylius and Sulu |
| [Font Awesome Free](https://github.com/FortAwesome/Font-Awesome/blob/7.2.0/svgs/brands/neos.svg) | 7.2.0 | [CC-BY-4.0](https://creativecommons.org/licenses/by/4.0/) | Copyright 2026 Fonticons, Inc. | `public/assets/icons/frameworks/neos.svg` |
| [Sylius](https://github.com/Sylius/Sylius/blob/bfa8c93d922ed11bfa3f2f5982cab714bd134c66/src/Sylius/Bundle/AdminBundle/Resources/assets/images/sylius-logo.svg) | `bfa8c93` | MIT | Copyright (c) 2011-present Sylius Sp. z o.o. | `public/assets/icons/frameworks/sylius.svg` (symbol only, monochrome) |
| [Sulu](https://github.com/sulu/sulu/blob/ae5269b82aa4cc6daf21187c9a4b89fb2d88a9ea/src/Sulu/Bundle/AdminBundle/Tests/Application/config/iconSet/svgs/sulu-only.svg) | `ae5269b` | MIT | Copyright (c) 2020 Sulu GmbH | `public/assets/icons/frameworks/sulu.svg` (`viewBox` added, monochrome) |
| [SVG Loaders](https://github.com/SamHerbert/SVG-Loaders) | — | MIT | (c) 2014 Sam Herbert | `public/assets/icons/animated/*.svg` |
| [Raleway](https://github.com/impallari/Raleway) | v37 | OFL-1.1 | Copyright 2010 The Raleway Project Authors | `public/assets/fonts/raleway/*.woff2` |
| [Nunito](https://github.com/googlefonts/nunito) | v32 | OFL-1.1 | Copyright 2014 The Nunito Project Authors | `public/assets/fonts/nunito/*.woff2` |
| [DejaVu fonts](https://dejavu-fonts.github.io) | 2.37 | Bitstream Vera and Arev font licenses (DejaVu changes public domain) | Copyright (c) 2003 Bitstream, Inc.; (c) 2006 Tavmjong Bah | `resources/fonts/pdf/dejavusans*` (converted for tc-lib-pdf); full text in `resources/fonts/pdf/LICENSE-DejaVu` |

## Framework and CMS logos

The logos in `public/assets/icons/frameworks/` only identify the software an application
runs and do not imply endorsement. They remain trademarks of their owners; the licenses above
cover the SVG files, not trademark rights. Drupal licenses its logo separately under its
[trademark policy](https://www.drupal.org/trademark).

## MIT License

Applies to Chart.js, Popper, Tippy.js, Tabulator, Bulma, Feather, SVG Loaders,
Sylius and Sulu, each with the copyright notice listed above.

```text
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## BSD 2-Clause License

Applies to htmx and _hyperscript, Copyright (c) 2020, Big Sky Software.

```text
Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

## SIL Open Font License 1.1

Applies to Raleway and Nunito, each with the copyright notice listed above.

```text
This Font Software is licensed under the SIL Open Font License, Version 1.1.
This license is copied below, and is also available with a FAQ at:
https://openfontlicense.org

-----------------------------------------------------------
SIL OPEN FONT LICENSE Version 1.1 - 26 February 2007
-----------------------------------------------------------

PREAMBLE
The goals of the Open Font License (OFL) are to stimulate worldwide
development of collaborative font projects, to support the font creation
efforts of academic and linguistic communities, and to provide a free and
open framework in which fonts may be shared and improved in partnership
with others.

The OFL allows the licensed fonts to be used, studied, modified and
redistributed freely as long as they are not sold by themselves. The
fonts, including any derivative works, can be bundled, embedded,
redistributed and/or sold with any software provided that any reserved
names are not used by derivative works. The fonts and derivatives,
however, cannot be released under any other type of license. The
requirement for fonts to remain under this license does not apply
to any document created using the fonts or their derivatives.

DEFINITIONS
"Font Software" refers to the set of files released by the Copyright
Holder(s) under this license and clearly marked as such. This may
include source files, build scripts and documentation.

"Reserved Font Name" refers to any names specified as such after the
copyright statement(s).

"Original Version" refers to the collection of Font Software components as
distributed by the Copyright Holder(s).

"Modified Version" refers to any derivative made by adding to, deleting,
or substituting -- in part or in whole -- any of the components of the
Original Version, by changing formats or by porting the Font Software to a
new environment.

"Author" refers to any designer, engineer, programmer, technical
writer or other person who contributed to the Font Software.

PERMISSION & CONDITIONS
Permission is hereby granted, free of charge, to any person obtaining
a copy of the Font Software, to use, study, copy, merge, embed, modify,
redistribute, and sell modified and unmodified copies of the Font
Software, subject to the following conditions:

1) Neither the Font Software nor any of its individual components,
in Original or Modified Versions, may be sold by itself.

2) Original or Modified Versions of the Font Software may be bundled,
redistributed and/or sold with any software, provided that each copy
contains the above copyright notice and this license. These can be
included either as stand-alone text files, human-readable headers or
in the appropriate machine-readable metadata fields within text or
binary files as long as those fields can be easily viewed by the user.

3) No Modified Version of the Font Software may use the Reserved Font
Name(s) unless explicit written permission is granted by the corresponding
Copyright Holder. This restriction only applies to the primary font name as
presented to the users.

4) The name(s) of the Copyright Holder(s) and the Author(s) of the Font
Software shall not be used to promote, endorse or advertise any
Modified Version, except to acknowledge the contribution(s) of the
Copyright Holder(s) and the Author(s) or with their explicit written
permission.

5) The Font Software, modified or unmodified, in part or in whole,
must be distributed entirely under this license, and must not be
distributed under any other license. The requirement for fonts to
remain under this license does not apply to any document created
using the Font Software.

TERMINATION
This license becomes null and void if any of the above conditions are
not met.

DISCLAIMER
THE FONT SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO ANY WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT
OF COPYRIGHT, PATENT, TRADEMARK, OR OTHER RIGHT. IN NO EVENT SHALL THE
COPYRIGHT HOLDER BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
INCLUDING ANY GENERAL, SPECIAL, INDIRECT, INCIDENTAL, OR CONSEQUENTIAL
DAMAGES, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
FROM, OUT OF THE USE OR INABILITY TO USE THE FONT SOFTWARE OR FROM
OTHER DEALINGS IN THE FONT SOFTWARE.
```
